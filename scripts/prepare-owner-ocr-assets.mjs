import { createHash } from 'node:crypto';
import { copyFile, lstat, mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const REPOSITORY_ROOT = resolve(dirname(SCRIPT_PATH), '..');
const REQUIRED_CORE_VARIANTS = [
  'tesseract-core.wasm.js',
  'tesseract-core-simd.wasm.js',
  'tesseract-core-relaxedsimd.wasm.js',
  'tesseract-core-lstm.wasm.js',
  'tesseract-core-simd-lstm.wasm.js',
  'tesseract-core-relaxedsimd-lstm.wasm.js',
];

const sha256 = (contents) => createHash('sha256').update(contents).digest('hex');

function safeVersion(value, name) {
  if (typeof value !== 'string' || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(value)) {
    throw new Error(`Invalid ${name} package version.`);
  }

  return value;
}

function packageRoot(requireFromScript, packageName, lookupPaths) {
  const entryPath = requireFromScript.resolve(packageName, { paths: lookupPaths });
  let candidate = dirname(entryPath);

  while (candidate !== dirname(candidate)) {
    try {
      const metadata = JSON.parse(requireFromScript('node:fs').readFileSync(join(candidate, 'package.json'), 'utf8'));
      if (metadata.name === packageName) return { path: candidate, metadata };
    } catch {
      // Continue toward the filesystem root until this package's own manifest is found.
    }
    candidate = dirname(candidate);
  }

  throw new Error(`Could not locate installed package ${packageName}.`);
}

function assertInside(parent, child) {
  const pathFromParent = relative(parent, child);
  if (pathFromParent === '' || pathFromParent === '..' || pathFromParent.startsWith(`..${sep}`) || isAbsolute(pathFromParent)) {
    throw new Error('OCR output path must stay inside public/ocr.');
  }
}

async function assertNoSymlink(path) {
  try {
    if ((await lstat(path)).isSymbolicLink()) throw new Error('OCR output cannot contain a symbolic link.');
  } catch (error) {
    if (error?.code !== 'ENOENT') throw error;
  }
}

async function runtimeFiles(coreDirectory) {
  const names = (await readdir(coreDirectory, { withFileTypes: true }))
    .filter((entry) => entry.isFile() && /^tesseract-core.*\.(?:js|wasm)$/.test(entry.name))
    .map((entry) => entry.name)
    .sort();
  const missing = REQUIRED_CORE_VARIANTS.filter((name) => !names.includes(name));

  if (missing.length > 0) {
    throw new Error(`Tesseract core package is missing required variants: ${missing.join(', ')}.`);
  }

  return names;
}

function manifestFor(bundle, versions, paths, files) {
  return {
    schema_version: 1,
    bundle,
    versions,
    paths,
    files,
  };
}

function thirdPartyNotice(versions) {
  return [
    'SoleSpace browser OCR runtime components',
    '',
    `Tesseract.js ${versions.engine} (Apache-2.0): https://github.com/naptha/tesseract.js`,
    `Tesseract.js Core ${versions.core} (Apache-2.0): https://github.com/naptha/tesseract.js-core`,
    `English trained data @tesseract.js-data/eng ${versions.language} (MIT as declared by the package): https://github.com/naptha/tessdata`,
    '',
    'This bundle contains only the browser worker, core/WASM runtime, and English trained data.',
    'The reports and applicant documents are not included in this asset bundle.',
    '',
  ].join('\n');
}

export async function prepareOwnerOcrAssets({
  publicDirectory,
  workerPath,
  coreDirectory,
  languagePath,
  licenseFiles = [],
  versions,
  checkOnly = false,
}) {
  if (!publicDirectory || !workerPath || !coreDirectory || !languagePath || !versions) {
    throw new Error('Public and runtime source paths plus resolved package versions are required.');
  }

  const resolvedVersions = {
    engine: safeVersion(versions.engine, 'Tesseract.js'),
    core: safeVersion(versions.core, 'Tesseract core'),
    language: safeVersion(versions.language, 'English language data'),
  };
  const bundle = `tesseract-${resolvedVersions.engine}-core-${resolvedVersions.core}-eng-${resolvedVersions.language}`;
  const publicRoot = resolve(publicDirectory);
  const ocrRoot = resolve(publicRoot, 'ocr');
  const bundleRoot = resolve(ocrRoot, bundle);
  assertInside(ocrRoot, bundleRoot);

  const coreNames = await runtimeFiles(coreDirectory);
  const sourceFiles = [
    { source: workerPath, relativePath: 'worker.min.js' },
    ...coreNames.map((name) => ({ source: join(coreDirectory, name), relativePath: `core/${name}` })),
    { source: languagePath, relativePath: 'lang/eng.traineddata.gz' },
    ...licenseFiles,
    { contents: thirdPartyNotice(resolvedVersions), relativePath: 'THIRD-PARTY-NOTICES.txt' },
  ];
  const files = {};
  for (const file of sourceFiles) {
    const contents = file.contents ?? await readFile(file.source).catch(() => {
      throw new Error(`Required OCR runtime source is missing: ${file.relativePath}.`);
    });
    files[`ocr/${bundle}/${file.relativePath}`] = sha256(contents);
  }

  const paths = {
    worker: `ocr/${bundle}/worker.min.js`,
    core: `ocr/${bundle}/core/`,
    lang: `ocr/${bundle}/lang/`,
  };
  const expectedManifest = manifestFor(bundle, resolvedVersions, paths, files);
  const manifestPath = join(ocrRoot, 'manifest.json');

  if (checkOnly) {
    const actualManifest = JSON.parse(await readFile(manifestPath, 'utf8').catch(() => {
      throw new Error('Generated OCR manifest is missing; run prepare:owner-ocr first.');
    }));
    if (JSON.stringify(actualManifest) !== JSON.stringify(expectedManifest)) {
      throw new Error('Generated OCR manifest differs from the installed runtime packages.');
    }
    for (const [assetPath, expectedHash] of Object.entries(files)) {
      const localPath = resolve(publicRoot, ...assetPath.split('/'));
      assertInside(ocrRoot, localPath);
      await assertNoSymlink(localPath);
      const contents = await readFile(localPath).catch(() => {
        throw new Error(`Generated OCR runtime asset is missing: ${assetPath}.`);
      });
      if (sha256(contents) !== expectedHash) throw new Error(`Generated OCR runtime checksum differs: ${assetPath}.`);
    }

    return expectedManifest;
  }

  await mkdir(publicRoot, { recursive: true });
  await assertNoSymlink(ocrRoot);
  await mkdir(ocrRoot, { recursive: true });
  await assertNoSymlink(bundleRoot);
  await mkdir(bundleRoot, { recursive: true });

  for (const directory of ['core', 'lang', 'licenses']) {
    const targetDirectory = join(bundleRoot, directory);
    assertInside(ocrRoot, targetDirectory);
    await assertNoSymlink(targetDirectory);
    await mkdir(targetDirectory, { recursive: true });
  }

  for (const file of sourceFiles) {
    const target = join(bundleRoot, file.relativePath);
    assertInside(ocrRoot, target);
    await assertNoSymlink(target);
    if (file.contents) await writeFile(target, file.contents, 'utf8');
    else await copyFile(file.source, target);
  }
  await writeFile(manifestPath, `${JSON.stringify(expectedManifest, null, 2)}\n`, 'utf8');

  return expectedManifest;
}

function installedRuntimeSources() {
  const requireFromScript = createRequire(import.meta.url);
  const engine = packageRoot(requireFromScript, 'tesseract.js', [REPOSITORY_ROOT]);
  const core = packageRoot(requireFromScript, 'tesseract.js-core', [engine.path]);
  const english = packageRoot(requireFromScript, '@tesseract.js-data/eng', [REPOSITORY_ROOT]);

  return {
    publicDirectory: join(REPOSITORY_ROOT, 'public'),
    workerPath: join(engine.path, 'dist', 'worker.min.js'),
    coreDirectory: core.path,
    languagePath: join(english.path, '4.0.0_best_int', 'eng.traineddata.gz'),
    licenseFiles: [
      { source: join(engine.path, 'LICENSE.md'), relativePath: 'licenses/tesseract.js-LICENSE.md' },
      { source: join(core.path, 'LICENSE'), relativePath: 'licenses/tesseract.js-core-LICENSE' },
    ],
    versions: {
      engine: engine.metadata.version,
      core: core.metadata.version,
      language: english.metadata.version,
    },
  };
}

async function main() {
  const args = process.argv.slice(2);
  const unknown = args.filter((arg) => arg !== '--check');
  if (unknown.length > 0 || args.filter((arg) => arg === '--check').length > 1) {
    throw new Error('Usage: node scripts/prepare-owner-ocr-assets.mjs [--check]');
  }

  const manifest = await prepareOwnerOcrAssets({
    ...installedRuntimeSources(),
    checkOnly: args.includes('--check'),
  });
  process.stdout.write(`Owner OCR assets ${args.includes('--check') ? 'verified' : 'prepared'}: ${manifest.bundle}\n`);
}

if (process.argv[1] && resolve(process.argv[1]) === SCRIPT_PATH) {
  main().catch((error) => {
    process.stderr.write(`Owner OCR asset preparation failed: ${error.message}\n`);
    process.exitCode = 1;
  });
}
