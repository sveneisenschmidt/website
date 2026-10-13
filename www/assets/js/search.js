document.addEventListener("DOMContentLoaded", function () {
    // Die Suche läuft über die Schnittstelle von Pagefind, nicht über dessen
    // fertiges Suchfeld. Die Treffer stehen im Aufbau des Archivs: Monatszeile
    // mit Jahr und Anzahl, darunter Zeilen aus Tag, Titel und Vorschaubild.
    // Dafür sortiert die Seite nach Datum statt nach Trefferqualität. Den Index
    // legt der Build unter /pagefind/ ab.
    var LIMIT = 40;

    var MONTHS = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];

    var input = document.querySelector("[data-search-input]");
    var output = document.querySelector("[data-search-results]");
    if (!input || !output) return;

    var form = input.form;
    if (form) {
        form.addEventListener("submit", function (event) {
            event.preventDefault();
        });
    }

    var engine = null;
    var facts = null;

    // Ein Fehlschlag bleibt gemerkt: sonst stiege bei jedem getippten Zeichen ein
    // neuer Ladeversuch auf, der ebenso scheitert.
    function load() {
        if (engine) return engine;

        engine = import("/pagefind/pagefind.js")
            .then(function (module) {
                module.init();
                return module;
            })
            .catch(function () {
                return null;
            });

        return engine;
    }

    // Datum und Vorschaubild stehen neben dem Index, nicht darin.
    function load_facts() {
        if (facts) return facts;

        facts = fetch("/search-meta.json")
            .then(function (response) {
                return response.ok ? response.json() : {};
            })
            .catch(function () {
                return {};
            });

        return facts;
    }

    function bar(label, count) {
        var heading = document.createElement("h2");
        heading.className = "archive-month stream-title";

        var name = document.createElement("span");
        name.textContent = label;

        var total = document.createElement("span");
        total.textContent = count;

        heading.appendChild(name);
        heading.appendChild(total);
        return heading;
    }

    function row(result) {
        var item = document.createElement("li");
        item.className = "archive-item";

        var day = document.createElement("span");
        day.className = "archive-day";

        var stamp = document.createElement("time");
        stamp.dateTime = result.date;
        stamp.textContent = String(Number(result.date.slice(8, 10)));
        day.appendChild(stamp);

        var title = document.createElement("a");
        title.className = "archive-title";
        title.href = result.url;
        title.textContent = result.title;

        item.appendChild(day);
        item.appendChild(title);

        if (result.thumb) {
            var frame = document.createElement("span");
            frame.className = "thumb";

            var image = document.createElement("img");
            image.src = result.thumb;
            if (result.thumb2x) {
                image.srcset = result.thumb + " 1x, " + result.thumb2x + " 2x";
            }
            image.width = 48;
            image.height = 48;
            image.alt = "";
            image.loading = "lazy";
            image.decoding = "async";

            frame.appendChild(image);
            item.appendChild(frame);
        }

        return item;
    }

    function render(results) {
        var next = document.createDocumentFragment();

        if (!results.length) {
            next.appendChild(bar("Nothing found", 0));
            output.textContent = "";
            output.appendChild(next);
            return;
        }

        results.sort(function (a, b) {
            return a.date < b.date ? 1 : a.date > b.date ? -1 : 0;
        });

        var month = "";
        var list = null;

        results.forEach(function (result) {
            if (result.date.slice(0, 7) !== month) {
                month = result.date.slice(0, 7);

                var span = results.filter(function (other) {
                    return other.date.slice(0, 7) === month;
                }).length;

                var label = MONTHS[Number(result.date.slice(5, 7)) - 1] + " " + result.date.slice(0, 4);
                next.appendChild(bar(label, span));

                list = document.createElement("ul");
                list.className = "archive-list";
                next.appendChild(list);
            }

            list.appendChild(row(result));
        });

        output.textContent = "";
        output.appendChild(next);
    }

    var DATE = /^\d{4}-\d{2}-\d{2}$/;

    function clean(data, known) {
        var fact = known[data.url] || {};

        return {
            url: data.url,
            title: data.meta.title || data.url,
            date: DATE.test(fact.date) ? fact.date : "",
            thumb: fact.thumb || "",
            thumb2x: fact.thumb2x || ""
        };
    }

    // Der Suchbegriff steht in der Adresse, damit ein Neuladen ihn behält und
    // ein Treffer sich verlinken lässt. replaceState statt pushState, sonst
    // füllt jede getippte Zeichenfolge den Verlauf.
    function remember(term) {
        var next = term ? location.pathname + "?q=" + encodeURIComponent(term) : location.pathname;
        if (location.pathname + location.search !== next) {
            history.replaceState(null, "", next);
        }
    }

    function run() {
        var term = input.value.trim();
        remember(term);

        if (form) form.classList.toggle("has-term", !!input.value);

        if (!term) {
            output.textContent = "";
            return;
        }

        Promise.all([load(), load_facts()]).then(function (both) {
            var module = both[0];
            var known = both[1];

            if (!module) {
                output.textContent = "";
                output.appendChild(bar("Search unavailable", 0));
                return;
            }

            module.preload(term);

            return module.debouncedSearch(term).then(function (search) {
                if (search === null) return;
                if (input.value.trim() !== term) return;

                // Mehr als eine Bildschirmseite voll Treffer liest niemand, und
                // jeder geladene Treffer kostet einen eigenen Abruf.
                return Promise.all(
                    search.results.slice(0, LIMIT).map(function (result) {
                        return result.data();
                    })
                ).then(function (found) {
                    if (input.value.trim() !== term) return;

                    render(
                        found
                            .map(function (data) {
                                return clean(data, known);
                            })
                            .filter(function (result) {
                                return result.date;
                            })
                    );
                });
            });
        });
    }

    input.addEventListener("input", run);

    if (form) form.classList.toggle("has-term", !!input.value);

    var opening = new URLSearchParams(location.search).get("q");
    if (opening) {
        input.value = opening;
        run();
    }
});
