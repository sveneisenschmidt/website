// Sends a failed draft save of the Panel (POST .../changes/save) once more,
// and a failed save of a settings page (PATCH site, Website\Blog\Settings).
// The Panel sends PATCH as POST with the header x-http-method-override.
//
// The Panel saves the draft while the user types: the first change after a
// pause at once, then at most once per second. The web server of the host
// closes an idle connection after 2 seconds (measured in October 2026).
// Safari can send the POST over a connection that the server closes at that
// moment, and it does not send a POST again
// (https://developer.apple.com/forums/thread/796906). fetch() then throws a
// TypeError, and the Panel shows "Das Panel ist zur Zeit offline". The Panel
// removes the message only when the browser reports that it is online again.
// The browser was never offline, so the message stays until a reload.
//
// A second request is safe: every save sends the complete draft, so two
// equal saves give the same draft. A settings page sends all of its fields,
// so two equal saves give the same content. The Panel aborts a save when a
// newer one starts. An aborted save throws an AbortError and is not sent
// again.
(() => {
  const send = window.fetch.bind(window);
  const isSave = (request) => {
    const path = new URL(request.url).pathname;
    return path.endsWith("/changes/save") || (path.endsWith("/api/site") && request.headers.get("x-http-method-override") === "PATCH");
  };

  window.fetch = async (input, init) => {
    if (!(input instanceof Request) || input.method !== "POST" || !isSave(input)) {
      return send(input, init);
    }

    // fetch() reads the body of the request, so the copy for the second
    // request must exist before the first one.
    const copy = input.clone();

    try {
      return await send(input, init);
    } catch (error) {
      if (error.name !== "TypeError") {
        throw error;
      }
      return send(copy, init);
    }
  };
})();
