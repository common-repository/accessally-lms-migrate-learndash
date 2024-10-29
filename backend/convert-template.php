<tr class="accessally-learndash-list-existing-row">
	<td class="accessally-learndash-id-col">{{id}}</td>
	<td class="accessally-learndash-title-col">
		<a {{show-edit-link}} target="_blank" href="{{edit-link}}">{{name}}</a>
	</td>
	<td class="accessally-learndash-detail-col">{{details}}</td>
	<td class="accessally-learndash-convert-col">
		<select id="accessally-learndash-operation-{{id}}" data-dependency-source="accessally-learndash-operation-{{id}}">
			<option value="no">Do not convert</option>
			<option value="stage">Convert to a Stage-release course</option>
			<option value="alone">Convert to a Standalone course</option>
			<option value="wp">Convert to regular WordPress pages (Advanced)</option>
		</select>
		<div style="display:none" hide-toggle data-dependency="accessally-learndash-operation-{{id}}" data-dependency-value-not="no"
			 accessally-convert-course="{{id}}"
			 class="accessally-setting-convert-button">
			Convert
		</div>
	</td>
</tr>