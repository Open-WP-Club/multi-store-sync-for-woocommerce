const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');

test('plugin, npm manifests and latest changelog agree on the release version', () => {
    const plugin = read('wc-multi-store-sync.php');
    const version = plugin.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1];
    assert.match(version, /^\d+\.\d+\.\d+$/);
    assert.equal(plugin.match(/define\('WC_MSS_VERSION',\s*'([^']+)'\)/)?.[1], version);
    assert.equal(JSON.parse(read('package.json')).version, version);
    const lock = JSON.parse(read('package-lock.json'));
    assert.equal(lock.version, version);
    assert.equal(lock.packages[''].version, version);
    assert.equal(read('CHANGELOG.md').match(/^## (\d+\.\d+\.\d+)/m)?.[1], version);
});
