const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

async function editor() {
    const {JSDOM} = await import('jsdom');
    const dom = new JSDOM('<table><tr><td class="shfw-stock-history-note" data-entry-id="1"><span class="shfw-note-text">original</span></td></tr></table>', {runScripts: 'outside-only', url: 'https://example.test/wp-admin/post.php?post=10'});
    const $ = require('jquery')(dom.window);
    dom.window.jQuery = $;
    dom.window.shfwStockHistory = {ajaxurl: '/wp-admin/admin-ajax.php', nonce: 'test', maxLength: 500, i18n: {saveError: 'Please retry.'}};
    const requests = [];
    $.post = (url, data) => { const deferred = $.Deferred(); requests.push({data, deferred}); return deferred.promise(); };
    await new Promise(resolve => dom.window.addEventListener('load', resolve, {once: true}));
    dom.window.eval(fs.readFileSync(path.join(__dirname, '../../assets/js/admin-stock-history.js'), 'utf8'));
    dom.window.dispatchEvent(new dom.window.Event('load'));
    $('.shfw-stock-history-note').trigger('click');
    return {dom, $, requests};
}

test('500 emoji characters are allowed and a 501st is removed without splitting a pair', async () => {
    const {dom, $} = await editor();
    const input = $('input'); input.val('🙂'.repeat(501)).trigger('input');
    assert.equal(Array.from(input.val()).length, 500);
    assert.equal(input.val(), '🙂'.repeat(500));
    dom.window.close();
});

for (const failure of ['http', 'application']) test(`${failure} save failure preserves the draft and permits retry`, async () => {
    const {dom, $, requests} = await editor();
    const input = $('input'); input.val('ää € draft').trigger($.Event('keydown', {key: 'Enter'}));
    assert.equal(requests.length, 1); assert.equal(input.prop('disabled'), true);
    if (failure === 'http') requests[0].deferred.reject(); else requests[0].deferred.resolve({success: false});
    assert.equal(input.val(), 'ää € draft'); assert.equal(input.prop('disabled'), false);
    assert.equal($('.shfw-note-error[role="alert"]').text(), 'Please retry.');
    input.trigger($.Event('keydown', {key: 'Enter'}));
    assert.equal(requests.length, 2);
    requests[1].deferred.resolve({success: true, data: {note: 'ää € draft'}});
    assert.equal($('.shfw-note-text').text(), 'ää € draft'); assert.equal($('input').length, 0);
    assert.equal($('.shfw-note-error').length, 0);
    dom.window.close();
});

test('a successful response is rendered as text', async () => {
    const {dom, $, requests} = await editor();
    $('input').val('changed').trigger($.Event('keydown', {key: 'Enter'}));
    requests[0].deferred.resolve({success: true, data: {note: '<img src=x onerror="bad()">'}});
    assert.equal($('img').length, 0); assert.equal($('.shfw-note-text').text(), '<img src=x onerror="bad()">');
    dom.window.close();
});
