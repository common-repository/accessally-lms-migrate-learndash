<?php
/*
 Plugin Name: AccessAlly™ LMS Migration from LearnDash®
 Plugin URI: https://accessally.com/
 Description: This AccessAlly™ LMS Migration from LearnDash® plugin will convert your existing LearnDash courses into AccessAlly courses, so you don't lose your content when you disable LearnDash.
 Version: 1.0.1
 Author: AccessAlly
 Author URI: https://accessally.com/about/
 */

if (!class_exists('AccessAlly_LearndashConversion')) {
	class AccessAlly_LearndashConversion {
		/// CONSTANTS
		const VERSION = '1.0.1';
		const SETTING_KEY = '_accessally_learndash_conversion';
		const HELP_URL = 'https://accessally.com/';
		private static $PLUGIN_URI = '';

		public static function init() {
			self::$PLUGIN_URI = plugin_dir_url(__FILE__);
			if (is_admin()) {
				add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_administrative_resources'));
				add_action('admin_menu', array(__CLASS__, 'add_menu_pages'));
			}
			add_action('wp_ajax_accessally_learndash_convert', array(__CLASS__, 'convert_callback'));
			add_action('wp_ajax_accessally_learndash_revert', array(__CLASS__, 'revert_callback'));

			register_activation_hook(__FILE__, array(__CLASS__, 'do_activation_actions'));
			register_deactivation_hook(__FILE__, array(__CLASS__, 'do_deactivation_actions'));
		}
		public static function do_activation_actions() {
			delete_transient(self::SETTING_KEY);
			wp_cache_flush();
		}
		public static function do_deactivation_actions() {
			delete_transient(self::SETTING_KEY);
			wp_cache_flush();
		}
		public static function enqueue_administrative_resources($hook){
			if (strpos($hook, self::SETTING_KEY) !== false) {
				wp_enqueue_style('accessally-learndash-convert-backend', self::$PLUGIN_URI . 'backend/settings.css', false, self::VERSION);
				wp_enqueue_script('accessally-learndash-convert-backend', self::$PLUGIN_URI . 'backend/settings.js', array('jquery'), self::VERSION);

				// do not include the http or https protocol in the ajax url
				$admin_url = preg_replace("/^http:/i", "", admin_url('admin-ajax.php'));
				$admin_url = preg_replace("/^https:/i", "", $admin_url);

				wp_localize_script('accessally-learndash-convert-backend', 'accessally_learndash_convert_object',
					array('ajax_url' => $admin_url,
						'nonce' => wp_create_nonce('accessally-learndash-convert')
						));
			}
		}
		public static function add_menu_pages() {
			// Add the top-level admin menu
			$capability = 'manage_options';
			$menu_slug = self::SETTING_KEY;
			$results = add_menu_page('AccessAlly LearnDash Conv', 'AccessAlly LearnDash Conv', $capability, $menu_slug, array(__CLASS__, 'show_settings'), self::$PLUGIN_URI . 'backend/icon.png');
		}
		public static function show_settings() {
			if (!current_user_can('manage_options')) {
				wp_die('You do not have sufficient permissions to access this page.');
			}
			if (!self::is_accessally_active()) {
				wp_die('AccessAlly is not activated or outdated. Please install the latest version of AccessAlly before using the conversion tool.');
			}
			$operation_code = self::generate_setting_display();
			include (dirname(__FILE__) . '/backend/settings-display.php');
		}

		// <editor-fold defaultstate="collapsed" desc="utility function for checking AccessAlly dependencies">
		private static function is_accessally_active() {
			if (!class_exists('AccessAlly') || !class_exists('AccessAllySettingLicense') || !AccessAllySettingLicense::$accessally_enabled ||
				!class_exists('AccessAllyWizardProduct') || !method_exists('AccessAllyWizardProduct', 'merge_default_settings') ||
				!class_exists('AccessAllyWizardDrip') || !method_exists('AccessAllyWizardDrip', 'merge_default_settings')) {
				return false;
			}
			return true;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="retrieve database info">
		private static $default_settings = array('wizard' => array());
		private static function get_learndash_courses() {
			global $wpdb;

			$course_rows = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='sfwd-courses'", OBJECT_K);
			$courses = array();
			foreach ($course_rows as $course_row) {
				$courses[$course_row->ID] = array('raw' => $course_row, 'lessons' => array(), 'topics' => array());
			}
			$courses['unassigned'] = array('raw' => (object)array('ID' => '0', 'post_title' => 'Unassigned lessons / topics'),
				'lessons' => array(), 'topics' => array());

			$course_mapping = array();
			$course_mapping_entries = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='course_id'", OBJECT_K);
			foreach ($course_mapping_entries as $entry) {
				$course_mapping[$entry->post_id] = $entry->meta_value;
			}
			$lesson_mapping = array();
			$lesson_mapping_entries = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='lesson_id'", OBJECT_K);
			foreach ($lesson_mapping_entries as $entry) {
				$lesson_mapping[$entry->post_id] = $entry->meta_value;
			}
			$lessons = array();
			$lesson_rows = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='sfwd-lessons'", OBJECT_K);
			foreach ($lesson_rows as $lesson_row) {
				$lesson_id = $lesson_row->ID;
				$assigned_course_id = 'unassigned';
				if (isset($course_mapping[$lesson_id])) {
					$assigned_course_id = $course_mapping[$lesson_id];
				}
				if (!isset($courses[$assigned_course_id])) {
					$assigned_course_id = 'unassigned';
				}
				$courses[$assigned_course_id]['lessons'] []= $lesson_id;
				$lessons[$lesson_id] = array('raw' => $lesson_row, 'topics' => array());
			}

			$topics = array();
			$topic_rows = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='sfwd-topic'", OBJECT_K);
			foreach ($topic_rows as $topic_row) {
				$topic_id = $topic_row->ID;
				if (isset($lesson_mapping[$topic_id])) {
					$assigned_lesson_id = $lesson_mapping[$topic_id];
					if (isset($lessons[$assigned_lesson_id])) {
						$lessons[$assigned_lesson_id]['topics'] []= $topic_id;
					}
				} else {
					$assigned_course_id = 'unassigned';
					if (isset($course_mapping[$topic_id])) {
						$assigned_course_id = $course_mapping[$topic_id];
					}
					if (!isset($courses[$assigned_course_id])) {
						$assigned_course_id = 'unassigned';
					}
					$courses[$assigned_course_id]['topics'] []= $topic_id;
				}
				$topics[$topic_id] = array('raw' => $topic_row);
			}
			return array('courses' => $courses, 'lessons' => $lessons, 'topics' => $topics);
		}
		public static function get_settings() {
			$setting = get_option(self::SETTING_KEY, false);
			if (!is_array($setting)) {
				$setting = self::$default_settings;
			} else {
				$setting = wp_parse_args($setting, self::$default_settings);
			}
			if (!isset($setting['wizard']) || !is_array($setting['wizard'])) {
				$setting['wizard'] = array();
			}

			return $setting;
		}
		public static function set_settings($settings) {
			$settings = wp_parse_args($settings, self::$default_settings);
			$successfully_added = add_option(self::SETTING_KEY, $settings, '', 'no');
			if (!$successfully_added) {
				update_option(self::SETTING_KEY, $settings);
			}
			return $settings;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="generate display code (used for initial display and ajax call back)">
		private static function generate_learndash_topic_display($topic_id, $learndash_data) {
			$code = '';
			if (isset($learndash_data['topics'][$topic_id])) {
				$topic_details = $learndash_data['topics'][$topic_id];
				$topic_db_entry = $topic_details['raw'];
				$code .= '<li>';
				$code .= '- Topic: ' . esc_html($topic_db_entry->post_title) . ' (' . $topic_id . ')';
				$code .= '</li>';
			}
			return $code;
		}
		private static function generate_learndash_lesson_display($lesson_id, $lesson_details, $learndash_data) {
			$lesson_db_entry = $lesson_details['raw'];
			$code = '- Lesson: ' . esc_html($lesson_db_entry->post_title) . ' (' . $lesson_id . ')';
			$code .= '<ul>';

			foreach ($lesson_details['topics'] as $topic_id) {
				$code .= self::generate_learndash_topic_display($topic_id, $learndash_data);
			}
			$code .= '</ul>';
			return $code;
		}
		private static function generate_learndash_course_display($code, $course_id, $course_details, $learndash_data) {
			$course_db_entry = $course_details['raw'];

			$code = str_replace('{{id}}', esc_html($course_id), $code);
			$course_edit_link = '#';
			$show_edit_link_css = 'style="display:none"';
			if ($course_id > 0) {
				$course_edit_link = admin_url('post.php?action=edit?post=' . $course_id);
				$show_edit_link_css = '';
			}
			$code = str_replace('{{edit-link}}', esc_attr($course_edit_link), $code);
			$code = str_replace('{{show-edit-link}}', $show_edit_link_css, $code);
			$code = str_replace('{{name}}', esc_html($course_db_entry->post_title), $code);

			$details = '<ul>';
			foreach ($course_details['lessons'] as $lesson_id) {
				if (isset($learndash_data['lessons'][$lesson_id])) {
					$lesson_details = $learndash_data['lessons'][$lesson_id];
					$details .= '<li>';
					$details .= self::generate_learndash_lesson_display($lesson_id, $lesson_details, $learndash_data);
					$details .= '</li>';
				}
			}
			foreach ($course_details['topics'] as $topic_id) {
				$details .= self::generate_learndash_topic_display($topic_id, $learndash_data);
			}
			$details .= '</ul>';

			$code = str_replace('{{details}}', $details, $code);

			return $code;
		}
		private static function generate_converted_course_display($row_code, $course_id, $wizard_course, $wizard_url_base) {
			$row_code = str_replace('{{name}}', esc_html($wizard_course['name']), $row_code);
			if (empty($wizard_course['type'])) {
				$row_code = str_replace('{{edit-link}}', '#', $row_code);
				$row_code = str_replace('{{show-edit}}', 'style="display:none"', $row_code);
			} else {
				$row_code = str_replace('{{edit-link}}', esc_attr($wizard_url_base . '&show-' . $wizard_course['type'] . '=' . $wizard_course['option-key']), $row_code);
				$row_code = str_replace('{{show-edit}}', '', $row_code);
			}
			$row_code = str_replace('{{course-id}}', esc_html($course_id), $row_code);
			return $row_code;
		}
		public static function generate_setting_display() {
			$code = file_get_contents(dirname(__FILE__) . '/backend/settings-template.php');

			$converted_posts = self::get_settings();
			$learndash_data = self::get_learndash_courses();
			$learndash_code = '';
			$learndash_template = file_get_contents(dirname(__FILE__) . '/backend/convert-template.php');
			foreach ($learndash_data['courses'] as $course_id => $course_details) {
				if (!isset($converted_posts['wizard'][$course_id])) {	// do not show already converted courses
					if ('unassigned' === $course_id) {
						// if all lessons and topics are assigned to courses, then do not show the unassigned course
						if (empty($course_details['lessons']) && empty($course_details['topics'])) {
							continue;
						}
					}
					$learndash_code .= self::generate_learndash_course_display($learndash_template, $course_id, $course_details, $learndash_data);
				}
			}
			$code = str_replace('{{learndash-courses}}', $learndash_code, $code);

			$existing_courses = '';
			if (!empty($converted_posts['wizard'])) {
				$existing_row_template = file_get_contents(dirname(__FILE__) . '/backend/existing-template.php');
				$wizard_url_base = admin_url('admin.php?page=_accessally_setting_wizard');
				foreach ($converted_posts['wizard'] as $course_id => $wizard_course) {
					$existing_courses .= self::generate_converted_course_display($existing_row_template, $course_id, $wizard_course, $wizard_url_base);
				}
			}

			$code = str_replace('{{existing-courses}}', $existing_courses, $code);

			if (!empty($existing_courses)) {
				$code = str_replace('{{show-existing}}', '', $code);
			} else {
				$code = str_replace('{{show-existing}}', 'style="display:none"', $code);
			}

			return $code;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="Create AccessAlly standalone course structure">
		private static $page_setting_template = array('type' => 'page', 'name' => '', 'is-changed' => 'no', 'page-template-select' => '0', 'checked-existing' => 'no',
			'status' => 'new', 'post-edit-link' => '#', 'post-id' => 0,
			'success-message' => '', 'error-message' => '');
		private static function create_accessally_wizard_page_from_raw_db($db_entry, $module_id = 0) {
			$result_page = self::$page_setting_template;
			$result_page['name'] = $db_entry->post_title;
			$result_page['is-changed'] = 'yes';
			$result_page['page-template-select'] = $db_entry->ID;
			$result_page['checked-existing'] = 'yes';
			$result_page['module'] = $module_id;
			return $result_page;
		}
		private static function create_accessally_standalone_course($course_details, $learndash_data) {
			$wizard_data = AccessAllyWizardProduct::$default_product_settings;
			$wizard_data['name'] = 'LearnDash Course: ' . $course_details['raw']->post_title;

			$api_settings = AccessAllySettingSetup::get_api_settings();
			$wizard_data['system'] = $api_settings['system'];

			if (!empty($course_details['topics'])) {
				foreach ($course_details['topics'] as $topic_id) {
					if (isset($learndash_data['topics'][$topic_id])) {
						$topic_details = $learndash_data['topics'][$topic_id];
						$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($topic_details['raw'], 0);
					}
				}
			}
			if (!empty($course_details['lessons'])) {
				foreach ($course_details['lessons'] as $lesson_id) {
					if (isset($learndash_data['lessons'][$lesson_id])) {
						$lesson_details = $learndash_data['lessons'][$lesson_id];
						$lesson_db_entry = $lesson_details['raw'];

						$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($lesson_db_entry, 0);

						foreach ($lesson_details['topics'] as $topic_id) {
							if (isset($learndash_data['topics'][$topic_id])) {
								$topic_details = $learndash_data['topics'][$topic_id];
								$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($topic_details['raw'], 0);
							}
						}
					}
				}
			}

			$wizard_data = AccessAllyWizardProduct::merge_default_settings($wizard_data);

			$wizard_data = AccessAllyUtilities::set_incrementing_settings(AccessAllyWizardProduct::SETTING_KEY_WIZARD_PRODUCT,
				AccessAllyWizardProduct::SETTING_KEY_WIZARD_PRODUCT_NUMBER, $wizard_data, AccessAllyWizardProduct::$default_product_settings, true, false);
			return $wizard_data;
		}
		private static function create_accessally_stage_release_course($course_details, $learndash_data) {
			$wizard_data = AccessAllyWizardDrip::$default_drip_settings;
			$course_db_entry = $course_details['raw'];
			$wizard_data['name'] = 'LearnDash Course: ' . $course_db_entry->post_title;

			$api_settings = AccessAllySettingSetup::get_api_settings();
			$wizard_data['system'] = $api_settings['system'];

			$wizard_data['pages'][0] = self::create_accessally_wizard_page_from_raw_db($course_db_entry, 0);

			$module_count = 0;

			if (!empty($course_details['topics'])) {
				foreach ($course_details['topics'] as $topic_id) {
					if (isset($learndash_data['topics'][$topic_id])) {
						$topic_details = $learndash_data['topics'][$topic_id];
						$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($topic_details['raw'], 0);
					}
				}
			}
			if (!empty($course_details['lessons'])) {
				foreach ($course_details['lessons'] as $lesson_id) {
					if (isset($learndash_data['lessons'][$lesson_id])) {
						$lesson_details = $learndash_data['lessons'][$lesson_id];
						$lesson_db_entry = $lesson_details['raw'];

						++$module_count;
						$module_wizard_data = AccessAllyWizardDrip::$default_module_settings;
						$module_wizard_data['name'] = $lesson_db_entry->post_title;
						$wizard_data['modules'][$module_count] = $module_wizard_data;

						$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($lesson_db_entry, $module_count);

						foreach ($lesson_details['topics'] as $topic_id) {
							if (isset($learndash_data['topics'][$topic_id])) {
								$topic_details = $learndash_data['topics'][$topic_id];
								$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($topic_details['raw'], $module_count);
							}
						}
					}
				}
			}

			$wizard_data = AccessAllyWizardDrip::merge_default_settings($wizard_data);
			$wizard_data = AccessAllyUtilities::set_incrementing_settings(AccessAllyWizardDrip::SETTING_KEY_WIZARD_DRIP,
				AccessAllyWizardDrip::SETTING_KEY_WIZARD_DRIP_NUMBER, $wizard_data, AccessAllyWizardDrip::$default_drip_settings, true, false);
			return $wizard_data;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="database post type update">
		private static function get_custom_post_to_convert($course_id, $course_details, $learndash_data) {
			$course_id = intval($course_id);
			$course_ids = array();
			if ($course_id > 0) {
				$course_ids []= $course_id;
			}
			$lesson_ids = array();
			$topic_ids = array();

			if (!empty($course_details['topics'])) {
				foreach ($course_details['topics'] as $topic_id) {
					if (isset($learndash_data['topics'][$topic_id])) {
						$topic_ids []= $topic_id;
					}
				}
			}
			if (!empty($course_details['lessons'])) {
				foreach ($course_details['lessons'] as $lesson_id) {
					if (isset($learndash_data['lessons'][$lesson_id])) {
						$lesson_ids []= $lesson_id;

						$lesson_details = $learndash_data['lessons'][$lesson_id];

						foreach ($lesson_details['topics'] as $topic_id) {
							if (isset($learndash_data['topics'][$topic_id])) {
								$topic_ids []= $topic_id;
							}
						}
					}
				}
			}
			$all_ids = array_merge($course_ids, $lesson_ids, $topic_ids);
			return array('all' => $all_ids, 'courses' => $course_ids, 'lessons' => $lesson_ids, 'topics' => $topic_ids);
		}
		private static function raw_database_update($post_ids, $target_type) {
			if (empty($post_ids)) {
				return 0;
			}
			global $wpdb;

			$query = $wpdb->prepare("UPDATE {$wpdb->posts} SET post_type = %s WHERE ID in (" . implode(',', $post_ids) . ")", $target_type);
			$update_result = $wpdb->query($query);
			if (false === $update_result && $wpdb->last_error) {
				throw new Exception($wpdb->last_error);
			}
			return $update_result;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="Ajax callbacks: convert / revert LearnDash to page">
		public static function convert_callback() {
			$result = array('status' => 'error', 'message' => 'Unknown error. Please refresh the page and try again.');
			try {
				if (!self::is_accessally_active()) {
					throw new Exception('AccessAlly is not activated or outdated. Please install the latest version of AccessAlly before using the conversion tool.');
				}
				if (!isset($_REQUEST['id']) || !isset($_REQUEST['op']) || !isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'accessally-learndash-convert')) {
					throw new Exception('The page is outdated. Please refresh and try again.');
				}
				$course_id = sanitize_text_field($_REQUEST['id']);
				$operation = sanitize_text_field($_REQUEST['op']);
				if ('alone' !== $operation && 'stage' !== $operation && 'wp' !== $operation) {
					throw new Exception('Invalid convert operation. Please refresh and try again.');
				}
				$learndash_data = self::get_learndash_courses();

				if (!isset($learndash_data['courses'][$course_id])) {
					throw new Exception('The LearnDash course doesn\'t exist. Please refresh and try again.');
				}
				$course_details = $learndash_data['courses'][$course_id];

				$course_db_entry = $course_details['raw'];
				$course_name = $course_db_entry->post_title;

				$conversion_data = array('name' => $course_name);	// assign default value if the course is converted without creating a wizard course
				if ('stage' === $operation) {
					$created_course = self::create_accessally_stage_release_course($course_details, $learndash_data);
					$conversion_data = array('type' => 'stage', 'option-key' => $created_course['option-key'], 'name' => $created_course['name']);
				} elseif ('alone' === $operation) {
					$created_course = self::create_accessally_standalone_course($course_details, $learndash_data);
					$conversion_data = array('type' => 'alone', 'option-key' => $created_course['option-key'], 'name' => $created_course['name']);
				}
				$pages_to_convert = self::get_custom_post_to_convert($course_id, $course_details, $learndash_data);

				self::raw_database_update($pages_to_convert['all'], 'page');

				$conversion_data['converted'] = $pages_to_convert;

				$conversion_history = self::get_settings();
				$conversion_history['wizard'][$course_id] = $conversion_data;
				self::set_settings($conversion_history);

				$code = self::generate_setting_display();
				$result = array('status' => 'success', 'message' => 'The LearnDash Course has been converted.', 'code' => $code);
			} catch (Exception $e) {
				$result['status'] = 'error';
				$result['message'] = $e->getMessage() . ' Please refresh the page and try again.';
			}
			echo json_encode($result);
			die();
		}
		public static function revert_callback() {
			$result = array('status' => 'error', 'message' => 'Unknown error. Please refresh the page and try again.');
			try {
				if (!self::is_accessally_active()) {
					throw new Exception('AccessAlly is not activated or outdated. Please install the latest version of AccessAlly before using the conversion tool.');
				}
				if (!isset($_REQUEST['id']) || !isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'accessally-learndash-convert')) {
					throw new Exception('The page is outdated. Please refresh and try again.');
				}
				$course_id = sanitize_text_field($_REQUEST['id']);
				$conversion_history = self::get_settings();
				if (!isset($conversion_history['wizard'][$course_id])) {
					throw new Exception('Invalid course. Please refresh and try again.');
				}
				$converted_data = $conversion_history['wizard'][$course_id];
				$converted_pages = $converted_data['converted'];
				self::raw_database_update($converted_pages['courses'], 'sfwd-courses');
				self::raw_database_update($converted_pages['lessons'], 'sfwd-lessons');
				self::raw_database_update($converted_pages['topics'], 'sfwd-topic');

				unset($conversion_history['wizard'][$course_id]);
				self::set_settings($conversion_history);

				$code = self::generate_setting_display();
				$result = array('status' => 'success', 'message' => 'Reverting pages to LearnDash format completed.', 'code' => $code);
			} catch (Exception $e) {
				$result['status'] = 'error';
				$result['message'] = $e->getMessage() . ' Please refresh the page and try again.';
			}
			echo json_encode($result);
			die();
		}
		// </editor-fold>
	}
	AccessAlly_LearndashConversion::init();
}
