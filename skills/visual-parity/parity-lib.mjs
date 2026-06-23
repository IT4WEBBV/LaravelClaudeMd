// Pure, dependency-free logic for the visual-parity worklist.
// Imports NOTHING external so its tests run without npm install.

// pixelmatch paints changed pixels in the diff color (default red, full alpha);
// unchanged pixels are faded grayscale. A pixel is "changed" when it is strongly red.
export function maskFromDiff(data, width, height) {
  const mask = new Uint8Array(width * height);
  for (let p = 0; p < width * height; p++) {
    const r = data[p * 4], g = data[p * 4 + 1], b = data[p * 4 + 2];
    if (r > 200 && g < 80 && b < 80) mask[p] = 1;
  }
  return mask;
}

// 8-connected flood fill (iterative stack — no recursion, safe for big images).
export function clusterMask(mask, width, height, { minPixels = 12 } = {}) {
  const visited = new Uint8Array(width * height);
  const regions = [];
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const start = y * width + x;
      if (!mask[start] || visited[start]) continue;
      let minX = x, maxX = x, minY = y, maxY = y, count = 0;
      const stack = [start];
      visited[start] = 1;
      while (stack.length) {
        const p = stack.pop();
        const py = (p / width) | 0;
        const px = p - py * width;
        count++;
        if (px < minX) minX = px;
        if (px > maxX) maxX = px;
        if (py < minY) minY = py;
        if (py > maxY) maxY = py;
        for (let dy = -1; dy <= 1; dy++) {
          for (let dx = -1; dx <= 1; dx++) {
            if (dx === 0 && dy === 0) continue;
            const nx = px + dx, ny = py + dy;
            if (nx < 0 || ny < 0 || nx >= width || ny >= height) continue;
            const ni = ny * width + nx;
            if (mask[ni] && !visited[ni]) { visited[ni] = 1; stack.push(ni); }
          }
        }
      }
      if (count >= minPixels) {
        regions.push({ x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1, pixels: count });
      }
    }
  }
  return regions;
}
