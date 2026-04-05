import fs from 'node:fs';
import path from 'node:path';

let cachedManifest;

export function loadDemoManifest() {
    if (cachedManifest) {
        return cachedManifest;
    }

    const manifestPath = path.join(process.cwd(), 'storage', 'app', 'e2e', 'demo-manifest.json');

    cachedManifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

    return cachedManifest;
}
