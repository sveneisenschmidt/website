// The Panel view "Likes": the total, the likes of the last 30 days, the
// posts by likes and every like, newest first (Website\Likes\Likes). The
// file needs no build step: the Panel includes the Vue template compiler
// (option panel.vue.compiler, on by default) and compiles the template in
// the browser.
panel.plugin("website/likes", {
  components: {
    "k-likes-view": {
      props: { likes: Object },
      computed: {
        reports() {
          return [
            { label: "Gesamt", value: String(this.likes.total), info: "Likes" },
            { label: "Letzte 30 Tage", value: String(this.likes.month), info: "Likes" },
          ];
        },
      },
      methods: {
        // "09.09.2026": the likes have no time limit, so the date shows the
        // year. Noon local time keeps the date the same in every time zone.
        date(date) {
          return new Date(date + "T12:00:00").toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit", year: "numeric" });
        },
      },
      template: `
        <k-panel-inside class="k-likes-view">
          <k-header>Likes</k-header>
          <k-stats :reports="reports" size="large" class="likes-tiles" />

          <section class="likes-tables">
            <div>
              <k-headline>Posts nach Likes</k-headline>
              <k-empty v-if="likes.posts.length === 0" icon="heart">Noch keine Likes.</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Post</th><th class="likes-num">Likes</th></tr></thead>
                <tbody>
                  <tr v-for="(p, i) in likes.posts" :key="i">
                    <td><k-link v-if="p.url" :to="p.url" target="_blank">{{ p.title }}</k-link><span v-else>{{ p.title }}</span></td>
                    <td class="likes-num">{{ p.likes }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div>
              <k-headline>Neueste Likes</k-headline>
              <k-empty v-if="likes.likes.length === 0" icon="heart">Noch keine Likes.</k-empty>
              <table v-else class="blog-log">
                <thead><tr><th>Post</th><th class="likes-num">Datum</th></tr></thead>
                <tbody>
                  <tr v-for="(l, i) in likes.likes" :key="i">
                    <td><k-link v-if="l.url" :to="l.url" target="_blank">{{ l.title }}</k-link><span v-else>{{ l.title }}</span></td>
                    <td class="likes-num">{{ date(l.date) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </k-panel-inside>
      `,
    },
  },
});
