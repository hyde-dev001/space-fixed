import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { prepareOwnerOcrAssets } from './prepare-owner-ocr-assets.mjs';

async function fixture() {
  const root = await mkdtemp(join(tmpdir(), 'shop-owner-ocr-assets-'));
  const publicDirectory = join(root, 'public');
  const coreDirectory = join(root, 'sources', 'core');
  const languagePath = join(root, 'sources', 'eng.traineddata.gz');
  const workerSource = join(root, 'sources', 'worker.min.js');
  const licenseFiles = [
    { source: join(root, 'sources', 'tesseract.js-LICENSE.md'), relativePath: 'licenses/tesseract.js-LICENSE.md' },
    { source: join(root, 'sources', 'tesseract.js-core-LICENSE'), relativePath: 'licenses/tesseract.js-core-LICENSE' },
  ];
  await mkdir(coreDirectory, { recursive: true });
  await writeFile(workerSource, 'worker fixture');
  await writeFile(languagePath, 'language fixture');
  await writeFile(licenseFiles[0].source, 'engine license fixture');
  await writeFile(licenseFiles[1].source, 'core license fixture');
  await writeFile(join(coreDirectory, 'tesseract-core.wasm.js'), 'core js');
  await writeFile(join(coreDirectory, 'tesseract-core.wasm'), 'core wasm');
  await writeFile(join(coreDirectory, 'tesseract-core-simd.wasm.js'), 'simd core js');
  await writeFile(join(coreDirectory, 'tesseract-core-simd.wasm'), 'simd core wasm');
  await writeFile(join(coreDirectory, 'tesseract-core-relaxedsimd.wasm.js'), 'relaxed core js');
  await writeFile(join(coreDirectory, 'tesseract-core-relaxedsimd.wasm'), 'relaxed core wasm');
  await writeFile(join(coreDirectory, 'tesseract-core-lstm.wasm.js'), 'lstm core js');
  await writeFile(join(coreDirectory, 'tesseract-core-lstm.wasm'), 'lstm core wasm');
  await writeFile(join(coreDirectory, 'tesseract-core-simd-lstm.wasm.js'), 'simd lstm core js');
  await writeFile(join(coreDirectory, 'tesseract-core-simd-lstm.wasm'), 'simd lstm core wasm');
  await writeFile(join(coreDirectory, 'tesseract-core-relaxedsimd-lstm.wasm.js'), 'relaxed core js');
  await writeFile(join(coreDirectory, 'tesseract-core-relaxedsimd-lstm.wasm'), 'relaxed core wasm');
  await writeFile(join(coreDirectory, 'README.md'), 'not a runtime asset');

  return {
    root,
    publicDirectory,
    sources: {
      workerPath: workerSource,
      coreDirectory,
      languagePath,
      licenseFiles,
      versions: { engine: '7.0.0', core: '7.0.0', language: '1.0.0' },
    },
  };
}

const sha256 = (value) => createHash('sha256').update(value).digest('hex');

test('copies all local runtime assets into a versioned public bundle and verifies it read-only', async (t) => {
  const source = await fixture();
  t.after(() => rm(source.root, { recursive: true, force: true }));

  const manifest = await prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
  });
  const bundle = join(source.publicDirectory, ...manifest.paths.worker.split('/').slice(0, 2));
  const bundleFiles = await readdir(join(bundle, 'core'));

  assert.equal(manifest.bundle, 'tesseract-7.0.0-core-7.0.0-eng-1.0.0');
  assert.equal(manifest.paths.worker, `ocr/${manifest.bundle}/worker.min.js`);
  assert.equal(manifest.paths.core, `ocr/${manifest.bundle}/core/`);
  assert.equal(manifest.paths.lang, `ocr/${manifest.bundle}/lang/`);
  assert.equal(manifest.files[`ocr/${manifest.bundle}/THIRD-PARTY-NOTICES.txt`], sha256([
    'SoleSpace browser OCR runtime components',
    '',
    'Tesseract.js 7.0.0 (Apache-2.0): https://github.com/naptha/tesseract.js',
    'Tesseract.js Core 7.0.0 (Apache-2.0): https://github.com/naptha/tesseract.js-core',
    'English trained data @tesseract.js-data/eng 1.0.0 (MIT as declared by the package): https://github.com/naptha/tessdata',
    '',
    'This bundle contains only the browser worker, core/WASM runtime, and English trained data.',
    'The reports and applicant documents are not included in this asset bundle.',
    '',
  ].join('\n')));
  assert.equal(manifest.files[`ocr/${manifest.bundle}/licenses/tesseract.js-LICENSE.md`], sha256('engine license fixture'));
  assert.equal(manifest.files[`ocr/${manifest.bundle}/licenses/tesseract.js-core-LICENSE`], sha256('core license fixture'));
  assert.equal(await readFile(join(bundle, 'licenses', 'tesseract.js-LICENSE.md'), 'utf8'), 'engine license fixture');
  assert.deepEqual(bundleFiles.sort(), [
    'tesseract-core-lstm.wasm',
    'tesseract-core-lstm.wasm.js',
    'tesseract-core-relaxedsimd-lstm.wasm',
    'tesseract-core-relaxedsimd-lstm.wasm.js',
    'tesseract-core-relaxedsimd.wasm',
    'tesseract-core-relaxedsimd.wasm.js',
    'tesseract-core-simd-lstm.wasm',
    'tesseract-core-simd-lstm.wasm.js',
    'tesseract-core-simd.wasm',
    'tesseract-core-simd.wasm.js',
    'tesseract-core.wasm',
    'tesseract-core.wasm.js',
  ]);
  assert.equal(manifest.files[`ocr/${manifest.bundle}/worker.min.js`], sha256('worker fixture'));
  assert.equal(await readFile(join(bundle, 'worker.min.js'), 'utf8'), 'worker fixture');

  const before = await readFile(join(source.publicDirectory, 'ocr', 'manifest.json'), 'utf8');
  assert.deepEqual(await prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
    checkOnly: true,
  }), manifest);
  assert.equal(await readFile(join(source.publicDirectory, 'ocr', 'manifest.json'), 'utf8'), before);
});

test('re-preparation reflects changed resolved versions and asset contents without deleting old bundles', async (t) => {
  const source = await fixture();
  t.after(() => rm(source.root, { recursive: true, force: true }));

  const original = await prepareOwnerOcrAssets({ publicDirectory: source.publicDirectory, ...source.sources });
  await writeFile(source.sources.workerPath, 'updated worker fixture');
  const updated = await prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
    versions: { ...source.sources.versions, engine: '7.1.0' },
  });

  assert.notEqual(updated.bundle, original.bundle);
  assert.notEqual(updated.files[updated.paths.worker], original.files[original.paths.worker]);
  assert.ok(await readFile(join(source.publicDirectory, 'ocr', original.bundle, 'worker.min.js')));
});

test('fails before writing when a required source is missing or a version could escape the public OCR directory', async (t) => {
  const source = await fixture();
  t.after(() => rm(source.root, { recursive: true, force: true }));

  await assert.rejects(prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
    workerPath: join(source.root, 'missing-worker.min.js'),
  }), /missing/i);
  await assert.rejects(prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
    versions: { ...source.sources.versions, engine: '../outside' },
  }), /version/i);
  await assert.rejects(readFile(join(source.root, 'outside', 'worker.min.js')));
});

test('check mode fails for a missing or modified generated asset', async (t) => {
  const source = await fixture();
  t.after(() => rm(source.root, { recursive: true, force: true }));

  await assert.rejects(prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
    checkOnly: true,
  }));
  const manifest = await prepareOwnerOcrAssets({ publicDirectory: source.publicDirectory, ...source.sources });
  await writeFile(join(source.publicDirectory, ...manifest.paths.worker.split('/')), 'tampered');
  await assert.rejects(prepareOwnerOcrAssets({
    publicDirectory: source.publicDirectory,
    ...source.sources,
    checkOnly: true,
  }), /checksum|differ/i);
});
