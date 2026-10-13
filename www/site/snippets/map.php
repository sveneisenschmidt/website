<?php
/**
 * The container that assets/js/map.js turns into a map: the points as JSON
 * in data-points, the route (GeoJSON) in a JSON script element.
 * `Website\Blog\Tags::map()` removes the line end after the element.
 *
 * @var bool $zoomable
 * @var list<array{label: string, lat: float, lon: float}> $points
 * @var string $route
 */
?>
<div class="map"<?= $zoomable ? ' data-zoomable="true"' : '' ?> data-points="<?= htmlspecialchars(json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>"><?php if ($route !== ''): ?><script type="application/json"><?= $route ?></script><?php endif ?>

</div>
