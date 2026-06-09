import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';

export default class DirectoryVendorIndexController extends Controller {
    // URL parameters setup
    queryParams = ['page', 'query', 'country'];

    @tracked vendors = []; // Isse template mein loop karenge
    @tracked query = null;
    @tracked country = '';
    @tracked totalRecords = 0;

    @tracked page = 1;
    @tracked limit = 15;
    @tracked sort = '-created_at';

    countries = [
        { name: 'All Countries', code: '' },
        { name: 'Nigeria', code: 'NG' },
        { name: 'Ghana', code: 'GH' },
        { name: 'Kenya', code: 'KE' }
    ];

    @task({ restartable: true }) 
    *fetchVendorsTask() {
        // Route se jo 'type' (slug) aa raha hai
        const typeSlug = this.model?.type || '';
        const token = "flb_live_3TWrpgeE2iTkvJG0myIk"; 
        
        // Final API URL for Vendors
        let url = `https://app.travo.ng/v1/vendors?type=${typeSlug}&page=${this.page}&limit=${this.limit}&sort=${this.sort}`;
        
        if (!isBlank(this.query)) {
            url += `&query=${encodeURIComponent(this.query)}`;
        }
        
        if (!isBlank(this.country)) {
            url += `&country=${this.country}`;
        }

        try {
            const response = yield fetch(url, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = yield response.json();
            
            // Fleetbase vendors array 'data' key mein bhejta hai
            this.vendors = result.data ? result.data : (Array.isArray(result) ? result : []);

            this.totalRecords = result.meta?.total || this.items.length;

            
            if (typeof window !== 'undefined') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } catch (e) {
            console.error("Vendor fetch error:", e);
            this.vendors = [];
        }
    }

    @task({ restartable: true }) 
    *search({ target: { value } }) {
        yield timeout(400); // Debounce
        this.query = isBlank(value) ? null : value;
        this.page = 1; 
        yield this.fetchVendorsTask.perform();
    }

    @action
    updateCountry(event) {
        this.country = event.target.value;
        this.page = 1; 
        this.fetchVendorsTask.perform();
    }

    @action
    changePage(direction) {
        if (direction === 'next') {
            this.page++;
        } else if (this.page > 1) {
            this.page--;
        }
        this.fetchVendorsTask.perform();
    }
}