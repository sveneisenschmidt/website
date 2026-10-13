document.addEventListener("DOMContentLoaded", function () {
    var KEY = "stream-scroll";

    var nav = document.querySelector(".stream-filter");
    var list = document.querySelector(".writing-list");

    if (nav) {
        nav.addEventListener("click", function (event) {
            var link = event.target.closest("a");
            if (!link || event.metaKey || event.ctrlKey || event.shiftKey) return;

            if (link.closest(".far")) return;

            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    page: window.scrollY,
                    list: list ? list.scrollTop : 0
                }));
            } catch (e) {}
        });
    }

    var stored = null;
    try { stored = sessionStorage.getItem(KEY); } catch (e) {}
    if (stored === null) return;
    try { sessionStorage.removeItem(KEY); } catch (e) {}

    if (!nav) return;

    var at;
    try { at = JSON.parse(stored); } catch (e) { return; }
    if (!at) return;

    if (at.page > 0) window.scrollTo({ top: at.page, behavior: "instant" });
    if (list && at.list > 0) list.scrollTop = at.list;
});
