<div class="wrap">
<h2 style="display:none;"><?php _e('AccessAlly LearnDash Conversion'); ?></h2>

<div id="accessally-learndash-convert-wait-overlay">
	<div class="accessally-learndash-convert-wait-content">
		<img src="<?php echo AccessAlly_LearndashConversion::$PLUGIN_URI; ?>backend/wait.gif" alt="wait" width="128" height="128" />
	</div>
</div>
<div class="accessally-setting-container">
	<div class="accessally-setting-title">AccessAlly - LearnDash Custom Post Conversion</div>
	<div class="accessally-setting-section">
		<div class="accessally-setting-message-container">
			<p>Use this tool to convert Courses, Lessons and Topics created in LearnDash to regular WordPress pages, so they can be re-used after LearnDash has been deactivated.</p>
			<ol>
				<li>Conversion does not modify the content of the course / lesson / topic.</li>
				<li>The conversion process can be reverted, so LearnDash courses / lessons / topics can be restored.</li>
				<li>Courses can be automatically converted to AccessAlly Course Wizard courses. <strong>Important:</strong> These are created as <strong>Drafts</strong> and they need to be further customized before they are published.</li>
			</ol>
		</div>
	</div>
	<?php echo $operation_code; ?>
</div>
</div>