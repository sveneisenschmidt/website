document.addEventListener("DOMContentLoaded", function () {
    // Die Treffer kommen beim Tippen von /search.json, schon nach Datum
    // sortiert. Sie stehen im Aufbau des Archivs: Monatszeile mit Jahr und
    // Anzahl, darunter Zeilen aus Tag, Titel und Vorschaubild. Mit ?q= in der
    // Adresse stehen sie schon vom Server in der Seite.
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

    function load(term) {
        return fetch("/search.json?q=" + encodeURIComponent(term))
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .catch(function () {
                return null;
            });
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

        load(term).then(function (results) {
            if (input.value.trim() !== term) return;

            if (!results) {
                output.textContent = "";
                output.appendChild(bar("Search unavailable", 0));
                return;
            }

            render(results);
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
