const bk_postview_main = {
	time_delay: 8000,
	end_point: null,
	post_id: null,
	init: function () {
		this.end_point = bbk_post_view.api_public;
		this.post_id = bbk_post_view.post_id;

		setTimeout(() => {
			bk_postview_main.setPostView();
		}, this.time_delay);
	},
	setPostView: function () {
		// Skip background/prerendered tabs: they are not a real view yet.
		if (document.visibilityState !== 'visible') {
			return;
		}

		const url = `${this.end_point}/set-post-views`;
		const data = { post_id: this.post_id };

		if (navigator.sendBeacon) {
			const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
			navigator.sendBeacon(url, blob);
			return;
		}

		fetch(url, {
			method: 'POST',
			keepalive: true,
			body: JSON.stringify(data),
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
		}).catch(() => {});
	},
};

window.addEventListener('load', () => bk_postview_main.init());
