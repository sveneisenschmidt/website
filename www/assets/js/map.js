document.addEventListener("DOMContentLoaded", function () {
    function init(el) {
        var points = JSON.parse(el.dataset.points);
        var latlngs = points.map(function (p) {
            return [p.lat, p.lon];
        });

        // A map allows panning and zooming only when the post's toggle
        // "Karte zoombar" (field `mapzoom`) is on. The scroll wheel always
        // scrolls the page and never zooms the map.
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
        // The map uses the standard OpenStreetMap tiles in light and dark
        // mode. At a fractional zoom level, thin gaps can show between the
        // tiles. The container background has the color of the tiles, so the
        // gaps are not visible.
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);
        var ink = function () {
            return "#000000";
        };
        el.style.backgroundColor = "#f2efe9";

        var markers = points.map(function (p) {
            var marker = L.circleMarker([p.lat, p.lon], {
                radius: 4,
                stroke: false,
                fillColor: ink(),
                fillOpacity: 1,
            }).addTo(map);
            return { point: p, marker: marker };
        });

        // A label near the left or right edge opens towards the center of the
        // map, so the container does not cut it off. When a label would
        // overlap an earlier label or a marker, or reach outside the map, the
        // other directions are tried. The direction with the lowest
        // badness() score wins, and a score of 0 ends the search.
        function bindLabels() {
            var size = map.getSize();
            var mapRect = el.getBoundingClientRect();
            var placed = [];

            // The dots of the other markers count as obstacles too, so a label
            // covers a marker only when every direction overlaps something.
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
                // A text node: Leaflet would read a string as HTML.
                m.marker.bindTooltip(document.createTextNode(m.point.label), {
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
