(function () {
	'use strict';

	var lb = document.createElement('div');
	lb.className = 'strava-lightbox';
	lb.innerHTML =
		'<div class="strava-lightbox-inner">' +
		'<span class="strava-lightbox-close" role="button" aria-label="Close">&times;</span>' +
		'<div class="strava-lightbox-content"></div>' +
		'</div>';
	document.body.appendChild(lb);

	var lbContent = lb.querySelector('.strava-lightbox-content');

	function open(html) {
		lbContent.innerHTML = html;
		lb.classList.add('is-open');
	}
	function close() {
		lb.classList.remove('is-open');
		lbContent.innerHTML = '';
	}

	lb.querySelector('.strava-lightbox-close').addEventListener('click', close);
	lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

	document.addEventListener('click', function (e) {
		var item = e.target.closest('.strava-strip-item');
		if (!item) return;

		if (item.dataset.type === 'video') {
			var src = item.dataset.src || '';
			if (src) open('<video controls autoplay src="' + src + '"></video>');
		} else {
			var img = item.querySelector('img');
			if (img) open('<img src="' + img.src + '" alt="' + (img.alt || '') + '">');
		}
	});
})();
