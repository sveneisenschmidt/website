document.addEventListener("DOMContentLoaded", function () {
    // While the visitor types, the script loads the result list as HTML from
    // /search.json. The server renders it with the same snippets as the
    // search page with ?q= in the URL.
    var input = document.querySelector("[data-search-input]");
    var output = document.querySelector("[data-search-results]");
    if (!input || !output) return;

    var form = input.form;
    if (form) {
        form.addEventListener("submit", function (event) {
            event.preventDefault();
        });
    }

    // Kirby does not cache a request with a query string, so every request to
    // search.json runs the search on the server. A request starts only after
    // a pause of 200 ms in typing, and the next keystroke aborts it.
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

    // The query goes into the URL, so a reload keeps it and a search can be
    // linked. replaceState() changes the current history entry; pushState()
    // would add an entry for every typed character.
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
