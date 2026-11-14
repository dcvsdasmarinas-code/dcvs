import assert from 'node:assert/strict';
import fs from 'node:fs';

globalThis.window = {};
const { computeInteriorLabelPoint } = await import('../src/layers/barangay-overlay.js');

function isInside(point, ring) {
  let inside = false;
  for (let index = 0, previous = ring.length - 1; index < ring.length; previous = index++) {
    const currentPoint = ring[index];
    const previousPoint = ring[previous];
    const crosses = (currentPoint[1] > point[1]) !== (previousPoint[1] > point[1])
      && point[0] < ((previousPoint[0] - currentPoint[0]) * (point[1] - currentPoint[1]))
        / (previousPoint[1] - currentPoint[1]) + currentPoint[0];
    if (crosses) inside = !inside;
  }
  return inside;
}

function isInsideGeometry(point, geometry) {
  const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
  return polygons.some((rings) =>
    isInside(point, rings[0]) && !rings.slice(1).some((ring) => isInside(point, ring))
  );
}

const concaveRing = [
  [120.9, 14.3],
  [120.94, 14.3],
  [120.94, 14.34],
  [120.93, 14.34],
  [120.93, 14.31],
  [120.91, 14.31],
  [120.91, 14.34],
  [120.9, 14.34],
  [120.9, 14.3]
];
const concaveLabel = computeInteriorLabelPoint({
  type: 'Polygon',
  coordinates: [concaveRing]
});
assert.ok(concaveLabel);
assert.ok(isInside(concaveLabel, concaveRing), 'label point stays inside a concave barangay');

const outerRing = [
  [120.9, 14.3],
  [120.94, 14.3],
  [120.94, 14.34],
  [120.9, 14.34],
  [120.9, 14.3]
];
const holeRing = [
  [120.91, 14.31],
  [120.93, 14.31],
  [120.93, 14.33],
  [120.91, 14.33],
  [120.91, 14.31]
];
const holeLabel = computeInteriorLabelPoint({
  type: 'Polygon',
  coordinates: [outerRing, holeRing]
});
assert.ok(holeLabel);
assert.ok(isInside(holeLabel, outerRing), 'label point stays inside the polygon');
assert.equal(isInside(holeLabel, holeRing), false, 'label point does not land inside a hole');
assert.equal(computeInteriorLabelPoint({ type: 'Point', coordinates: [120.9, 14.3] }), null);

const barangays = JSON.parse(fs.readFileSync(new URL('../geo-map/barangays-boundary.json', import.meta.url), 'utf8'));
for (const feature of barangays.features) {
  const labelPoint = computeInteriorLabelPoint(feature.geometry);
  assert.ok(labelPoint, `label point is generated for ${feature.properties.name}`);
  assert.ok(isInsideGeometry(labelPoint, feature.geometry), `label point is inside ${feature.properties.name}`);
}

console.log(`Barangay label placement checks passed for ${barangays.features.length} boundaries.`);
