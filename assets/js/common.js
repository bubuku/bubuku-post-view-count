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
	// Bucketed device width — never the exact pixel width (fingerprinting vector).
	getViewportBucket: function () {
		const w = window.innerWidth;
		if (w < 576) return '<576';
		if (w < 992) return '576-991';
		if (w < 1400) return '992-1399';
		return '>=1400';
	},
	// Classified referrer — never the raw host or full URL, only one of a fixed set of buckets.
	getReferrerClass: function () {
		const ref = document.referrer;
		if (!ref) return 'direct';

		let host;
		try {
			host = new URL(ref).host;
		} catch (e) {
			return 'other';
		}

		if (host === location.host) return 'internal';

		const SEARCH = ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com', 'yandex.'];
		const SOCIAL = ['facebook.com', 'twitter.com', 'x.com', 't.co', 'linkedin.com', 'instagram.com', 'pinterest.', 'reddit.com'];
		const AI = ['chatgpt.com', 'claude.ai', 'perplexity.ai', 'copilot.microsoft.com', 'gemini.google.com'];

		if (SEARCH.some((h) => host.includes(h))) return 'search';
		if (SOCIAL.some((h) => host.includes(h))) return 'social';
		if (AI.some((h) => host.includes(h))) return 'ai';
		return 'other';
	},
	setPostView: function () {
		// Skip background/prerendered tabs: they are not a real view yet.
		if (document.visibilityState !== 'visible') {
			return;
		}

		const url = `${this.end_point}/set-post-views`;
		const data = {
			post_id: this.post_id,
			viewport: this.getViewportBucket(),
			referrer: this.getReferrerClass(),
		};

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
