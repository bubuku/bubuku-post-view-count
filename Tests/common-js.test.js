const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = `${ fs.readFileSync( 'assets/js/common.js', 'utf8' ) }
globalThis.classifyReferrer = (referrer, pageUrl) => {
	document.referrer = referrer;
	const url = new URL(pageUrl);
	location.host = url.host;
	location.hostname = url.hostname;
	location.search = url.search;
	return bk_postview_main.getReferrerClass();
};
globalThis.classifyAiAssistant = (referrer, pageUrl) => {
	document.referrer = referrer;
	const url = new URL(pageUrl);
	location.host = url.host;
	location.hostname = url.hostname;
	location.search = url.search;
	return bk_postview_main.getAiAssistantClass();
};
globalThis.tracker = bk_postview_main;`;

const context = {
	Blob,
	URL,
	URLSearchParams,
	bbk_post_view: { api_public: '', post_id: 1, respect_dnt: false },
	document: { referrer: '', visibilityState: 'visible', addEventListener: () => {} },
	fetch: () => Promise.resolve(),
	location: { host: 'example.com', hostname: 'example.com', search: '' },
	navigator: { doNotTrack: '0' },
	clearTimeout: () => {},
	setTimeout: () => {},
	window: { addEventListener: () => {}, doNotTrack: '0', innerWidth: 1200 },
};

vm.runInNewContext( source, context );

const classify = ( referrer, pageUrl = 'https://example.com/post/' ) =>
	context.classifyReferrer( referrer, pageUrl );

const classifyAssistant = ( referrer, pageUrl = 'https://example.com/post/' ) =>
	context.classifyAiAssistant( referrer, pageUrl );

test( 'classifies known AI assistant domains as AI referrals', () => {
	const referrers = [
		'https://chatgpt.com/c/example',
		'https://chat.openai.com/c/example',
		'https://claude.ai/chat/example',
		'https://www.perplexity.ai/search/example',
		'https://copilot.microsoft.com/chats/example',
		'https://gemini.google.com/app/example',
	];

	for ( const referrer of referrers ) {
		assert.equal( classify( referrer ), 'ai', referrer );
	}
} );

test( 'uses explicit AI attribution when the browser omits the referrer', () => {
	assert.equal(
		classify( '', 'https://example.com/post/?utm_source=chatgpt.com' ),
		'ai'
	);
	assert.equal(
		classify( '', 'https://example.com/post/?ref=claude' ),
		'ai'
	);
	assert.equal(
		classify( '', 'https://example.com/post/?utm_source=GEMINI' ),
		'ai'
	);
} );

test( 'keeps search, social, internal and direct traffic in their own buckets', () => {
	assert.equal(
		classify( 'https://www.google.com/search?q=example' ),
		'search'
	);
	assert.equal( classify( 'https://t.co/example' ), 'social' );
	assert.equal( classify( 'https://example.com/another-post/' ), 'internal' );
	assert.equal( classify( '' ), 'direct' );
} );

test( 'does not accept partial-domain false positives', () => {
	assert.equal( classify( 'https://evilchatgpt.com/example' ), 'other' );
	assert.equal( classify( 'https://microsoft.com/example' ), 'other' );
	assert.equal( classify( 'https://notgoogle.example/example' ), 'other' );
} );

test( 'resolves the specific AI assistant behind each known domain', () => {
	assert.equal( classifyAssistant( 'https://chatgpt.com/c/example' ), 'chatgpt' );
	assert.equal( classifyAssistant( 'https://chat.openai.com/c/example' ), 'chatgpt' );
	assert.equal( classifyAssistant( 'https://claude.ai/chat/example' ), 'claude' );
	assert.equal( classifyAssistant( 'https://www.perplexity.ai/search/example' ), 'perplexity' );
	assert.equal( classifyAssistant( 'https://copilot.microsoft.com/chats/example' ), 'copilot' );
	assert.equal( classifyAssistant( 'https://gemini.google.com/app/example' ), 'gemini' );
} );

test( 'resolves the specific AI assistant from explicit attribution', () => {
	assert.equal(
		classifyAssistant( '', 'https://example.com/post/?utm_source=chatgpt.com' ),
		'chatgpt'
	);
	assert.equal(
		classifyAssistant( '', 'https://example.com/post/?ref=claude' ),
		'claude'
	);
	assert.equal(
		classifyAssistant( '', 'https://example.com/post/?utm_source=GEMINI' ),
		'gemini'
	);
} );

test( 'returns no AI assistant for non-AI or absent referrers', () => {
	assert.equal( classifyAssistant( '' ), '' );
	assert.equal( classifyAssistant( 'https://www.google.com/search?q=example' ), '' );
	assert.equal( classifyAssistant( 'https://evilchatgpt.com/example' ), '' );
} );

test( 'uses five cumulative visible seconds as the view threshold', () => {
	assert.equal( context.tracker.time_delay, 5000 );
} );

test( 'pauses in a hidden tab and resumes only the remaining visible time', () => {
	let now = 0;
	let scheduledDelay = null;
	let cleared = false;
	context.window.performance = { now: () => now };
	context.setTimeout = ( callback, delay ) => {
		scheduledDelay = delay;
		return callback;
	};
	context.clearTimeout = () => {
		cleared = true;
	};
	context.tracker.elapsed_visible = 0;
	context.tracker.visible_since = null;
	context.tracker.timer_id = null;
	context.tracker.sent = false;
	context.document.visibilityState = 'visible';

	context.tracker.updateVisibility();
	assert.equal( scheduledDelay, 5000 );
	now = 2000;
	context.document.visibilityState = 'hidden';
	context.tracker.updateVisibility();
	assert.equal( context.tracker.elapsed_visible, 2000 );
	assert.equal( cleared, true );

	context.document.visibilityState = 'visible';
	context.tracker.updateVisibility();
	assert.equal( scheduledDelay, 3000 );
} );

test( 'falls back to fetch when sendBeacon declines the payload', () => {
	let fetchCalls = 0;
	context.bbk_post_view.api_public = 'https://example.com/wp-json/bbk_postview/v1';
	context.tracker.end_point = context.bbk_post_view.api_public;
	context.tracker.post_id = 9;
	context.tracker.sent = false;
	context.navigator.sendBeacon = () => false;
	context.fetch = () => {
		fetchCalls += 1;
		return Promise.resolve();
	};

	context.tracker.setPostView();
	assert.equal( fetchCalls, 1 );
	context.tracker.setPostView();
	assert.equal( fetchCalls, 1, 'a view must only be sent once' );
} );

test( 'a failed fetch returns to pending so an online retry is deduplicated server-side', async () => {
	context.tracker.sent = false;
	context.tracker.state = 'pending';
	context.navigator.sendBeacon = () => false;
	context.fetch = () => Promise.reject( new Error( 'offline' ) );

	context.tracker.setPostView();
	await Promise.resolve();
	await Promise.resolve();

	assert.equal( context.tracker.sent, false );
	assert.equal( context.tracker.state, 'pending' );
} );
