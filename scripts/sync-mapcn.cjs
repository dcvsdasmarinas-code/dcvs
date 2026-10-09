const fs = require('fs');
const path = require('path');

const distDir = path.resolve(__dirname, '../dist');
const source = path.join(distDir, 'mapcn.umd.cjs');
const target = path.join(distDir, 'mapcn.umd.js');

if (!fs.existsSync(source)) {
  console.error(`Missing built bundle: ${source}`);
  process.exit(1);
}

fs.copyFileSync(source, target);
console.log(`Copied ${path.basename(source)} to ${path.basename(target)}`);
