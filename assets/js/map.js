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

        points.forEach(function (p) {
            var marker = L.circleMarker([p.lat, p.lon], {
                radius: 6,
                color: "#d63a2f",
                fillColor: "#fff",
                fillOpacity: 1,
                weight: 2,
            }).addTo(map);
            if (p.label) {
                marker.bindTooltip(p.label, {
                    permanent: true,
                    direction: "top",
                    offset: [0, -8],
                });
            }
        });

        function draw(lines) {
            var line = L.polyline(lines, {
                color: "#d63a2f",
                weight: 3,
            }).addTo(map);
            map.fitBounds(line.getBounds(), { padding: [30, 30] });
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
