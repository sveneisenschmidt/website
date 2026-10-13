document.addEventListener("DOMContentLoaded", function () {
    // While typing, /search.json brings the result list as HTML, from the
    // same snippets as the search page with ?q= in the address.
    var input = document.querySelector("[data-search-input]");
    var output = document.querySelector("[data-search-results]");
    if (!input || !output) return;

    var form = input.form;
    if (form) {
        form.addEventListener("submit", function (event) {
            event.preventDefault();
        });
    }

    // search.json is not in the page cache: a request waits for a pause in
    // typing, and the next keystroke aborts it.
    var timer = 0;
    var request = null;

    function load(term) {
        request = new AbortController();
        var signal = request.signal;
        fetch("/search.json?q=" + encodeURIComponent(term), { signal: signal })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .catch(function () {
                return null;
            })
            .then(function (data) {
                if (signal.aborted) return;

                output.innerHTML = data
                    ? data.html
                    : '<h2 class="archive-month stream-title"><span>Search unavailable</span><span>0</span></h2>';
            });
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

    function run(delay) {
        var term = input.value.trim();
        remember(term);

        if (form) form.classList.toggle("has-term", !!input.value);

        clearTimeout(timer);
        if (request) request.abort();

        if (!term) {
            output.textContent = "";
            return;
        }

        timer = setTimeout(load, delay, term);
    }

    input.addEventListener("input", function () {
        run(200);
    });

    if (form) form.classList.toggle("has-term", !!input.value);

    var opening = new URLSearchParams(location.search).get("q");
    if (opening) {
        input.value = opening;
        run(0);
    }
});
