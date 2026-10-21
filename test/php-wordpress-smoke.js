const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

if (process.env.SERVERLESSWP_TESTING !== '1' || process.env.DATABASE !== 'wordpress_test' ||
    process.env.HOST !== '127.0.0.1:3306') {
    throw new Error('Smoke tests require the disposable CI MySQL database.');
}

const root = path.resolve(__dirname, '..');
fs.rmSync('/tmp/wp', { recursive: true, force: true });
fs.cpSync(path.join(root, 'wp'), '/tmp/wp', { recursive: true });
fs.writeFileSync('/tmp/wp/wp-content/mu-plugins/ci-smoke.php', `<?php
add_filter('pre_wp_mail', '__return_true');
add_filter('woocommerce_allow_tracking', '__return_false');
`);
fs.writeFileSync('/tmp/wp/ci-fixture.php', `<?php
if (getenv('SERVERLESSWP_TESTING') !== '1') { http_response_code(404); exit; }
define('WP_INSTALLING', true);
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_blog_installed()) { wp_install('CI store', 'admin', 'admin@example.com', true, '', 'ci-password-only'); }
$plugins = [
  'woocommerce/woocommerce.php', 'wp-graphql/wp-graphql.php',
  'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php',
  'simple-jwt-login/simple-jwt-login.php', 'tidb-compatibility/tidb-compatibility.php',
  'catalog-filter-config/catalog-filter-config.php',
  'product-questions/product-questions.php', 'wishlist/wishlist.php',
];
foreach ($plugins as $plugin) {
  if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin)) { continue; }
  $result = activate_plugin($plugin, '', false, true);
  if (is_wp_error($result)) { throw new Exception($result->get_error_message()); }
}
update_option('woocommerce_cod_settings', ['enabled' => 'yes', 'enable_for_virtual' => 'yes']);
update_option('woocommerce_currency', 'USD');
update_option('woocommerce_default_country', 'US:CA');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_enable_checkout_login_reminder', 'no');
update_option('permalink_structure', '/%postname%/');
if (class_exists('WC_Install')) { WC_Install::create_tables(); }
header('Content-Type: application/json');
echo json_encode(['installed' => true, 'plugins' => get_option('active_plugins')]);
`);
fs.writeFileSync('/tmp/wp/ci-product.php', `<?php
if (getenv('SERVERLESSWP_TESTING') !== '1') { http_response_code(404); exit; }
require __DIR__ . '/wp-load.php';
WC_Install::install();
delete_transient('_wc_activation_redirect');
update_option('woocommerce_onboarding_profile', ['completed' => true]);
$product = new WC_Product_Simple();
$product->set_name('CI test lure');
$product->set_status('publish');
$product->set_regular_price('10.00');
$product->set_virtual(true);
$product->set_catalog_visibility('visible');
$product->save();
header('Content-Type: application/json');
echo json_encode(['id' => $product->get_id(), 'version' => PHP_VERSION, 'uploadProgress' => ini_get('session.upload_progress.enabled')]);
`);

fs.writeFileSync('/tmp/wp/ci-upload.php', `<?php
if (getenv('SERVERLESSWP_TESTING') !== '1') { http_response_code(404); exit; }
header('Content-Type: application/json');
$file = $_FILES['file'] ?? null;
echo json_encode(['name' => $file['name'] ?? null, 'error' => $file['error'] ?? null,
  'uploaded' => $file ? is_uploaded_file($file['tmp_name']) : false]);
`);

const { handler } = require('../api/index.js');
let cookies = new Map();
async function request(url, { method = 'GET', body, headers = {} } = {}) {
    const [pathname, query = ''] = url.split('?');
    const response = await handler({
        path: pathname,
        rawQueryString: query,
        httpMethod: method,
        headers: { host: 'ci.example.test', 'content-type': 'application/json',
            cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join('; '), ...headers },
        body: body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body),
        isBase64Encoded: false,
    });
    for (const cookie of response.cookies || []) {
        const [pair] = cookie.split(';');
        const index = pair.indexOf('=');
        if (index !== -1) cookies.set(pair.slice(0, index), pair.slice(index + 1));
    }
    const text = response.isBase64Encoded ? Buffer.from(response.body, 'base64').toString() : response.body;
    return { ...response, text, json: () => JSON.parse(text) };
}
function status(response, expected) {
    assert.equal(response.statusCode, expected, JSON.stringify({ location: response.headers?.location, body: response.text.slice(0, 1500) }));
}

(async () => {
    let response = await request('/ci-fixture.php');
    status(response, 200);
    assert.ok(response.json().plugins.includes('woocommerce/woocommerce.php'));
    assert.ok(response.json().plugins.includes('wp-graphql/wp-graphql.php'));
    response = await request('/ci-product.php');
    status(response, 200);
    const product = response.json();
    assert.equal(product.version, '8.3.36');
    assert.ok(!Number(product.uploadProgress));
    console.log('PASS: WordPress install, plugins, PHP version and startup hardening');
    const boundary = 'ci-safe-upload-boundary';
    response = await request('/ci-upload.php', {
        method: 'POST', headers: { 'content-type': `multipart/form-data; boundary=${boundary}` },
        body: `--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="ci-upload.txt"\r\nContent-Type: text/plain\r\n\r\nSafe upload fixture\r\n--${boundary}--\r\n`,
    });
    status(response, 200);
    assert.deepEqual(response.json(), { name: 'ci-upload.txt', error: 0, uploaded: true });
    console.log('PASS: normal multipart file upload reaches patched PHP');


    response = await request('/wp-login.php');
    status(response, 200);
    assert.match(response.text, /user_login/);
    response = await request('/wp-login.php', {
        method: 'POST', body: 'log=admin&pwd=ci-password-only&wp-submit=Log+In&testcookie=1',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
    });
    status(response, 302);
    assert.ok([...cookies.keys()].some(name => name.startsWith('wordpress_logged_in_')));
    response = await request('/wp-admin/');
    for (let redirects = 0; [301, 302, 303].includes(response.statusCode) && redirects < 6; redirects++) {
        const location = response.headers.location;
        assert.ok(location, 'admin redirect has a destination');
        const destination = new URL(location, 'https://ci.example.test');
        assert.equal(destination.host, 'ci.example.test');
        assert.ok(destination.pathname.startsWith('/wp-admin/'), `unexpected admin redirect: ${location}`);
        response = await request(destination.pathname + destination.search);
    }
    status(response, 200);
    assert.match(response.text, /id="wpbody"/);
    console.log('PASS: admin login and dashboard');
    cookies = new Map();

    response = await request('/index.php?rest_route=/wc/store/v1/products');
    status(response, 200);
    assert.ok(response.json().some(item => item.id === product.id));
    response = await request('/index.php?graphql', {
        method: 'POST', body: { query: '{ generalSettings { title } }' },
    });
    status(response, 200);
    assert.equal(response.json().data.generalSettings.title, 'CI store');
    console.log('PASS: REST product API and GraphQL');

    response = await request('/index.php?rest_route=/wc/store/v1/cart');
    status(response, 200);
    const token = response.headers['cart-token'];
    assert.ok(token, 'Store API supplies a cart token');
    const cartHeaders = { 'cart-token': token };
    response = await request('/index.php?rest_route=/wc/store/v1/cart/add-item', {
        method: 'POST', headers: cartHeaders, body: { id: product.id, quantity: 1 },
    });
    status(response, 200);
    assert.equal(response.json().items[0].id, product.id);
    response = await request('/index.php?rest_route=/wc/store/v1/checkout', {
        method: 'POST', headers: cartHeaders, body: {
            billing_address: { first_name: 'CI', last_name: 'Test', company: '',
                address_1: '1 Test Street', address_2: '', city: 'Los Angeles', state: 'CA',
                postcode: '90001', country: 'US', email: 'ci@example.com', phone: '2025550100' },
            payment_method: 'cod', payment_data: [], customer_note: 'Disposable CI test order',
        },
    });
    status(response, 200);
    assert.ok(response.json().order_id > 0);
    assert.ok(['processing', 'on-hold', 'completed'].includes(response.json().status));
    console.log('PASS: guest cart and checkout with cash-on-delivery (no external payment)');

    response = await request('/index.php?wc-api=wc_stripe', { method: 'POST', body: {} });
    assert.ok([200, 400, 401, 403].includes(response.statusCode), response.text);
    assert.doesNotMatch(response.text, /Fatal error|Uncaught|critical error/i);
    console.log(`PASS: Stripe callback reaches the gateway (HTTP ${response.statusCode}); live signed events are not tested`);
    process.exit(0);
})().catch(error => {
    console.error(error);
    process.exit(1);
});
