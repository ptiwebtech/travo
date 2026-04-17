import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';

export default class DirectoryPlaceIndexController extends Controller {
    // URL binding ke liye zaroori hai
    queryParams = ['page', 'query', 'country'];

    @tracked items = [];
    @tracked query = null;
    @tracked country = '';
    @tracked totalRecords = 0;

    @tracked page = 1;
    @tracked limit = 15; // Backend 30 de raha hai default, toh yahan 30 set karo
    @tracked sort = '-created_at';

    countries = [
        { name: 'All Countries', code: '' },
        { name: 'Nigeria', code: 'NG' },
        { name: 'India', code: 'IN' },
        { name: 'United Kingdom', code: 'GB' }
    ];

   
    @task({ restartable: true }) 
    *fetchPlacesTask() {
        const slug = this.model?.categoryName || '';
        const token = "flb_live_3TWrpgeE2iTkvJG0myIk"; 
        
        // URL mein limit aur sort dono add karo
        let url = `https://app.travo.ng/v1/places?type=${slug}&page=${this.page}&limit=${this.limit}&sort=${this.sort}&with[]=files`;
        
        if (!isBlank(this.query)) {
            url += `&query=${this.query}`;
        }
        
        if (!isBlank(this.country)) {
            url += `&country=${this.country}`;
        }

        try {
            const response = yield fetch(url, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = yield response.json();
            
            // Response key check: Fleetbase aksar result.data mein array deta hai
            this.items = result.data ? result.data : (Array.isArray(result) ? result : []);

            //this.totalRecords = result.meta?.total || this.items.length;

            console.log(this.items);
            
            // Scroll to top jab naya page load ho
            if (typeof window !== 'undefined') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } catch (e) {
            console.error("Fetch error:", e);
            this.items = [];
        }
    }

    @task({ restartable: true }) 
    *search({ target: { value } }) {
        yield timeout(400);
        this.query = isBlank(value) ? null : value;
        this.page = 1; 
        yield this.fetchPlacesTask.perform();
    }

    @action
    updateCountry(event) {
        this.country = event.target.value;
        this.page = 1; 
        this.fetchPlacesTask.perform();
    }

    @action
    changePage(direction) {
        if (direction === 'next') {
            this.page++;
        } else if (this.page > 1) {
            this.page--;
        }
        this.fetchPlacesTask.perform();
    }
}