<div id="accessally-learndash-convert-container">
	<div class="accessally-setting-section">
		<div class="accessally-setting-header">Existing LearnDash Courses</div>
		<div class="accessally-learndash-list-existing-container">
			<table class="accessally-learndash-list-existing">
				<tr>
					<th class="accessally-learndash-id-col">ID</th>
					<th class="accessally-learndash-title-col">Course name</th>
					<th class="accessally-learndash-detail-col">Details</th>
					<th class="accessally-learndash-convert-col">Conversion option</th>
				</tr>
				{{learndash-courses}}
			</table>
		</div>
	</div>
	<div class="accessally-setting-section" {{show-existing}}>
		<div class="accessally-setting-header">Converted courses</div>
		<div class="accessally-learndash-list-existing-container">
			<table class="accessally-learndash-list-existing">
				<tbody>
					<tr>
						<th class="accessally-learndash-list-existing-name-col">Name</th>
						<th class="accessally-learndash-list-existing-edit-col">Edit</th>
						<th class="accessally-learndash-list-existing-revert-col">Revert</th>
					</tr>
					{{existing-courses}}
				</tbody>
			</table>
		</div>
	</div>
</div>