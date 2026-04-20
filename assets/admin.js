(function ($) {
	'use strict';

	function renderPreview($preview, attachmentIds) {
		$preview.empty();

		attachmentIds.forEach(function (id) {
			const attachment = wp.media.attachment(id);
			attachment.fetch().then(function () {
				const data = attachment.toJSON();
				if (!data || !data.sizes) {
					return;
				}

				const thumb = (data.sizes.thumbnail || data.sizes.medium || data.sizes.full || {}).url;
				if (!thumb) {
					return;
				}

				$preview.append($('<img>', { src: thumb, alt: data.alt || '' }));
			});
		});
	}

	$(function () {
		const $idsInput = $('#mmm-photo-set-image-ids');
		const $preview = $('.mmm-image-preview');
		let frame;

		$('.mmm-select-images').on('click', function (event) {
			event.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select set images',
				button: { text: 'Use these images' },
				multiple: true
			});

			frame.on('open', function () {
				const ids = ($idsInput.val() || '')
					.split(',')
					.map(function (value) { return parseInt(value, 10); })
					.filter(Boolean);

				const selection = frame.state().get('selection');
				ids.forEach(function (id) {
					selection.add(wp.media.attachment(id));
				});
			});

			frame.on('select', function () {
				const selection = frame.state().get('selection').toJSON();
				const ids = selection.map(function (item) { return item.id; });
				$idsInput.val(ids.join(','));
				renderPreview($preview, ids);
			});

			frame.open();
		});

		$('.mmm-clear-images').on('click', function (event) {
			event.preventDefault();
			$idsInput.val('');
			$preview.empty();
		});
	});
}(jQuery));
