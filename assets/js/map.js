document.addEventListener("DOMContentLoaded", function () {
    function init(el) {
        var points = JSON.parse(el.dataset.points);
        var latlngs = points.map(function (p) {
            return [p.lat, p.lon];
        });

        // Dense maps opt into panning and zooming, the scroll wheel stays
        // with the page so the map never hijacks scrolling.
        var zoomable = el.dataset.zoomable === "true";
        var map = L.map(el, {
            zoomControl: zoomable,
            dragging: zoomable,
            scrollWheelZoom: false,
            doubleClickZoom: zoomable,
            touchZoom: zoomable,
            boxZoom: false,
            keyboard: zoomable,
            zoomSnap: 0.25,
        });
        // Voyager tiles in both color schemes, the map keeps its own light
        // look. The container background matches the tiles so the hairline
        // seams between tiles at fractional zoom stay invisible.
        L.tileLayer(
            "https://basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png",
            {
                maxZoom: 19,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
            },
        ).addTo(map);
        var ink = function () {
            return "#000000";
        };
        el.style.backgroundColor = "#fbf6ee";

        var markers = points.map(function (p) {
            var marker = L.circleMarker([p.lat, p.lon], {
                radius: 4,
                stroke: false,
                fillColor: ink(),
                fillOpacity: 1,
            }).addTo(map);
            return { point: p, marker: marker };
        });

        // Labels near the left/right edge open towards the map center so
        // they do not get clipped by the container. When a label would
        // overlap an earlier one (or stick out of the map), it tries the
        // remaining directions until it finds a free spot.
        function bindLabels() {
            var size = map.getSize();
            var mapRect = el.getBoundingClientRect();
            var placed = [];

            // Marker dots are obstacles too, so a label never covers a
            // neighbouring marker.
            markers.forEach(function (m) {
                var c = map.latLngToContainerPoint([m.point.lat, m.point.lon]);
                m.rect = {
                    left: c.x - 6,
                    top: c.y - 6,
                    right: c.x + 6,
                    bottom: c.y + 6,
                };
            });

            var offsets = {
                top: [0, -8],
                bottom: [0, 8],
                left: [-10, 0],
                right: [10, 0],
            };

            function bind(m, direction) {
                m.marker.unbindTooltip();
                m.marker.bindTooltip(m.point.label, {
                    permanent: true,
                    direction: direction,
                    offset: offsets[direction],
                });
                var r = m.marker
                    .getTooltip()
                    .getElement()
                    .getBoundingClientRect();
                return {
                    left: r.left - mapRect.left,
                    top: r.top - mapRect.top,
                    right: r.right - mapRect.left,
                    bottom: r.bottom - mapRect.top,
                };
            }

            function overlapArea(a, b) {
                var w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
                var h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
                return w > 0 && h > 0 ? w * h : 0;
            }

            function badness(r, obstacles) {
                var score = 0;
                if (r.left < 0) score += (0 - r.left) * (r.bottom - r.top);
                if (r.top < 0) score += (0 - r.top) * (r.right - r.left);
                if (r.right > size.x)
                    score += (r.right - size.x) * (r.bottom - r.top);
                if (r.bottom > size.y)
                    score += (r.bottom - size.y) * (r.right - r.left);
                obstacles.forEach(function (p) {
                    score += overlapArea(r, p);
                });
                return score;
            }

            markers.forEach(function (m) {
                if (!m.point.label) return;
                var x = map.latLngToContainerPoint([
                    m.point.lat,
                    m.point.lon,
                ]).x;
                var preferred = "top";
                if (x > size.x * 0.75) {
                    preferred = "left";
                } else if (x < size.x * 0.25) {
                    preferred = "right";
                }

                var candidates = [
                    preferred,
                    "top",
                    "bottom",
                    "right",
                    "left",
                ].filter(function (d, i, arr) {
                    return arr.indexOf(d) === i;
                });

                var obstacles = placed.concat(
                    markers
                        .filter(function (o) {
                            return o !== m;
                        })
                        .map(function (o) {
                            return o.rect;
                        }),
                );

                var best = null;
                for (var i = 0; i < candidates.length; i++) {
                    var rect = bind(m, candidates[i]);
                    var score = badness(rect, obstacles);
                    if (!best || score < best.score) {
                        best = {
                            direction: candidates[i],
                            rect: rect,
                            score: score,
                        };
                    }
                    if (score === 0) break;
                }
                if (
                    m.marker.getTooltip().options.direction !== best.direction
                ) {
                    best.rect = bind(m, best.direction);
                }
                placed.push(best.rect);
            });
        }

        function draw(lines) {
            var line = L.polyline(lines, {
                color: "#d63a2f",
                weight: 3,
            }).addTo(map);
            map.fitBounds(line.getBounds(), { padding: [30, 30] });
            markers.forEach(function (m) {
                m.marker.bringToFront();
            });
            bindLabels();
            if (zoomable) {
                map.on("zoomend moveend", bindLabels);
            }
        }

        var routeScript = el.querySelector('script[type="application/json"]');
        if (routeScript) {
            var geo = JSON.parse(routeScript.textContent);
            routeScript.remove();
            draw(
                geo.coordinates.map(function (c) {
                    return [c[1], c[0]];
                }),
            );
        } else {
            draw(latlngs);
        }
    }

    document.querySelectorAll(".map[data-points]").forEach(function (el) {
        if (el.offsetWidth > 0) {
            init(el);
            return;
        }
        var observer = new ResizeObserver(function () {
            if (el.offsetWidth > 0) {
                observer.disconnect();
                init(el);
            }
        });
        observer.observe(el);
    });
});
