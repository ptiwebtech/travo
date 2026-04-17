import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class DirectoryVendorDetailsController extends Controller {
    @service modalsManager;
    
    @tracked vendor = null;
    @tracked isLoading = false;

    async fetchVendorDetails(idFromRoute) {
        const id = idFromRoute || this.model?.id; 
        if (!id) return;

        this.isLoading = true;
        this.vendor = null; 
        
        const token = "flb_live_3TWrpgeE2iTkvJG0myIk"; 
        // Vendors ke liye query param public_id ya direct ID check karein
        const url = `https://app.travo.ng/v1/vendors?public_id=${id}`; 
    
        try {
            const response = await fetch(url, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();
            
            const dataArray = Array.isArray(result) ? result : (result.data || []);
            
            if (dataArray.length > 0) {
                this.vendor = dataArray[0];
            }
        } catch (e) {
            console.error("Vendor Fetch Error:", e);
        } finally {
            this.isLoading = false;
        }
    }

    get formattedCreatedAt() {
        if (!this.vendor?.created_at) return 'N/A';
        const date = new Date(this.vendor.created_at);
        return date.toLocaleDateString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric'
        });
    }
}