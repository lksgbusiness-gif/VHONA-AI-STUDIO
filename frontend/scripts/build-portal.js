const fs = require('fs');
const path = require('path');

const projectRoot = path.resolve(__dirname, '..');
const srcDir = path.join(projectRoot, 'portal-bundle');
const defaultBuildDir = path.resolve(projectRoot, '../wordpress/wp-content/plugins/client-portal/build');
const buildDir = process.env.BUILD_PATH
  ? path.resolve(projectRoot, process.env.BUILD_PATH)
  : defaultBuildDir;
const outDir = path.join(buildDir, 'portal');

function ensureDir(dir) {
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

function copyFile(fileName) {
  const srcPath = path.join(srcDir, fileName);
  const destPath = path.join(outDir, fileName);

  if (!fs.existsSync(srcPath)) {
    throw new Error(`Missing ${fileName} in portal-bundle source.`);
  }

  fs.copyFileSync(srcPath, destPath);
  return destPath;
}

function main() {
  ensureDir(outDir);

  const cssPath = copyFile('index.css');
  const jsPath = copyFile('index.js');

  console.log(`Portal bundle copied to ${outDir}`);
  console.log(` - ${path.relative(projectRoot, jsPath)}`);
  console.log(` - ${path.relative(projectRoot, cssPath)}`);
}

main();
