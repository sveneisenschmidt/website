// The Panel view "Ausrüstung": every camera and lens of the photos in three
// lists, the cameras, the lenses and the retired gear (Website\Gear\Gear).
// Each row has the value from the photo, a field for the name on the site
// and a switch for retired. The switch moves the row at once. "Speichern"
// sends all names and switches to the API route gear/save. The file needs
// no build step, as plugins/likes/index.js explains.
panel.plugin("website/gear", {
  components: {
    "k-gear-view": {
      props: { rows: Array },
      data() {
        return { list: this.rows, entries: this.entriesOf(this.rows), saving: false };
      },
      computed: {
        // The rows come sorted, cameras first, so the retired list has the
        // cameras first too.
        lists() {
          const active = (kind) => this.list.filter((row) => row.kind === kind && !this.entries[row.raw].retired);
          return [
            { title: "Kameras", rows: active("camera") },
            { title: "Objektive", rows: active("lens") },
            { title: "Retired", rows: this.list.filter((row) => this.entries[row.raw].retired) },
          ].filter((list) => list.rows.length > 0);
        },
      },
      methods: {
        // One entry per value from the photo. A value that is a camera and a
        // lens has two rows and one entry, so both rows show the same name.
        entriesOf(rows) {
          const entries = {};
          for (const row of rows) {
            entries[row.raw] = { name: row.name, retired: row.retired };
          }
          return entries;
        },
        async save() {
          this.saving = true;
          try {
            const result = await this.$api.post("gear/save", { entries: this.entries });
            this.list = result.rows;
            this.entries = this.entriesOf(result.rows);
            this.$panel.notification.success("Gespeichert");
          } catch (error) {
            // The fields keep what was typed.
            this.$panel.notification.error(error);
          } finally {
            this.saving = false;
          }
        },
      },
      template: `
        <k-panel-inside class="k-gear-view">
          <k-header>
            Ausrüstung
            <template #buttons>
              <k-button icon="check" variant="filled" :disabled="saving" @click="save">Speichern</k-button>
            </template>
          </k-header>
          <section v-for="group in lists" :key="group.title" class="gear-list">
            <k-headline>{{ group.title }}</k-headline>
            <table class="blog-log">
              <thead><tr><th>Wert aus dem Foto</th><th>Name auf der Seite</th><th class="gear-retired">Retired</th></tr></thead>
              <tbody>
                <tr v-for="row in group.rows" :key="row.kind + ':' + row.raw">
                  <td>{{ row.raw }}</td>
                  <td><k-input type="text" :value="entries[row.raw].name" :placeholder="row.raw" @input="entries[row.raw].name = $event" /></td>
                  <td class="gear-retired"><k-input type="toggle" :value="entries[row.raw].retired" @input="entries[row.raw].retired = $event" /></td>
                </tr>
              </tbody>
            </table>
          </section>
        </k-panel-inside>
      `,
    },
  },
});
