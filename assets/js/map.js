document.addEventListener("DOMContentLoaded", function () {
    function init(el) {
        var points = JSON.parse(el.dataset.points);
        var latlngs = points.map(function (p) {
            return [p.lat, p.lon];
        });

        var map = L.map(el, {
            zoomControl: false,
            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            touchZoom: false,
            boxZoom: false,
            keyboard: false,
            zoomSnap: 0.25,
        });
        var scheme = window.matchMedia("(prefers-color-scheme: dark)");
        var tileUrl = function () {
            return (
                "https://basemaps.cartocdn.com/" +
                (scheme.matches ? "dark_all" : "light_all") +
                "/{z}/{x}/{y}{r}.png"
            );
        };
        var tiles = L.tileLayer(tileUrl(), {
            maxZoom: 19,
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
        }).addTo(map);
        scheme.addEventListener("change", function () {
            tiles.setUrl(tileUrl());
        });

        var markers = points.map(function (p) {
            var marker = L.circleMarker([p.lat, p.lon], {
                radius: 6,
                color: "#d63a2f",
                fillColor: "#fff",
                fillOpacity: 1,
                weight: 2,
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

            function fits(r) {
                if (
                    r.left < 0 ||
                    r.top < 0 ||
                    r.right > size.x ||
                    r.bottom > size.y
                ) {
                    return false;
                }
                return !placed.some(function (p) {
                    return (
                        r.left < p.right &&
                        p.left < r.right &&
                        r.top < p.bottom &&
                        p.top < r.bottom
                    );
                });
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

                var rect = null;
                for (var i = 0; i < candidates.length; i++) {
                    rect = bind(m, candidates[i]);
                    if (fits(rect)) break;
                    if (i === candidates.length - 1) {
                        rect = bind(m, preferred);
                    }
                }
                placed.push(rect);
            });
        }

        function draw(lines) {
            var line = L.polyline(lines, {
                color: "#d63a2f",
                weight: 3,
            }).addTo(map);
            map.fitBounds(line.getBounds(), { padding: [30, 30] });
            bindLabels();
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
