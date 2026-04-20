(function () {
	'use strict';

	const instances = document.querySelectorAll('.mmm-gallery-shell');

	instances.forEach((gallery) => {
		const dataNode = gallery.nextElementSibling;
		if (!dataNode || !dataNode.classList.contains('mmm-lightbox-data')) {
			return;
		}

		let items = [];
		try {
			items = JSON.parse(dataNode.textContent || '[]');
		} catch (error) {
			items = [];
		}

		if (!items.length) {
			return;
		}

		const lightbox = gallery.querySelector('.mmm-lightbox');
		const image = lightbox.querySelector('.mmm-lightbox__image');
		const caption = lightbox.querySelector('.mmm-lightbox__caption');
		const closeButton = lightbox.querySelector('.mmm-lightbox__close');
		const prevButton = lightbox.querySelector('.mmm-lightbox__nav--prev');
		const nextButton = lightbox.querySelector('.mmm-lightbox__nav--next');
		let currentIndex = 0;

		function render(index) {
			const item = items[index];
			if (!item) {
				return;
			}

			currentIndex = index;
			image.src = item.image || '';
			image.alt = item.alt || '';
			caption.textContent = item.caption || '';
		}

		function open(index) {
			render(index);
			lightbox.hidden = false;
			document.body.classList.add('mmm-lightbox-open');
		}

		function close() {
			lightbox.hidden = true;
			document.body.classList.remove('mmm-lightbox-open');
			image.src = '';
		}

		function move(step) {
			const nextIndex = (currentIndex + step + items.length) % items.length;
			render(nextIndex);
		}

		gallery.querySelectorAll('[data-mmm-lightbox-index]').forEach((link) => {
			link.addEventListener('click', (event) => {
				event.preventDefault();
				open(parseInt(link.getAttribute('data-mmm-lightbox-index'), 10) || 0);
			});
		});

		closeButton.addEventListener('click', close);
		prevButton.addEventListener('click', () => move(-1));
		nextButton.addEventListener('click', () => move(1));

		lightbox.addEventListener('click', (event) => {
			if (event.target === lightbox) {
				close();
			}
		});

		document.addEventListener('keydown', (event) => {
			if (lightbox.hidden) {
				return;
			}

			if (event.key === 'Escape') {
				close();
			}

			if (event.key === 'ArrowLeft') {
				move(-1);
			}

			if (event.key === 'ArrowRight') {
				move(1);
			}
		});
	});
}());
