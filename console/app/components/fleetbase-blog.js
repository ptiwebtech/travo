import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FleetbaseBlogComponent extends Component {
    @service fetch;
    @tracked posts = [];
    @tracked isLoading = false;

    constructor() {
        super(...arguments);
        this.loadBlogPosts();
    }

    @action loadBlogPosts() {
        this.isLoading = true;

        return this.fetch
            .get('lookup/fleetbase-blog')
            .then((response) => {
                // Format the pubDate to remove time and timezone
                this.posts = response.map((post) => ({
                    ...post,
                    pubDate: new Date(post.pubDate).toDateString(), // Extracts only the date part (e.g., "Mon Apr 28 2025")
                }));
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
}
