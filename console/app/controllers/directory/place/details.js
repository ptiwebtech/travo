import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';

export default class DirectoryPlaceDetailsController extends Controller {
    @tracked place = null;
    @tracked isLoading = false;
    @service modalsManager;


    async fetchDetails(idFromRoute) {
        const id = idFromRoute || this.model?.id; 
        if (!id) return;

        this.isLoading = true;
        this.place = null; 
        
        const token = "flb_live_3TWrpgeE2iTkvJG0myIk"; 
        const url = `https://app.travo.ng/v1/places?public_id=${id}&with[]=files`; 
    
        try {
            const response = await fetch(url, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const result = await response.json();
            
            const dataArray = Array.isArray(result) ? result : (result.data || []);
            
            if (dataArray.length > 0) {
                const item = dataArray[0];
                this.place = item;

                // Coordinates handle karein controller mein hi
                if (item.location && item.location.coordinates) {
                    this.lng = item.location.coordinates[0];
                    this.lat = item.location.coordinates[1];
                }
            }
        } catch (e) {
            console.error("Fetch Error:", e);
        } finally {
            this.isLoading = false;
        }
    }

    get formattedCreatedAt() {
        if (!this.place?.created_at) return 'N/A';
        const date = new Date(this.place.created_at);
        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    }

    @action
    viewFile(file) {
        if (file.content_type && file.content_type.startsWith('image/')) {
            this.modalsManager.show('modals/image-viewer', {
                title: file.original_filename,
                imageSource: file.url,
                acceptButtonText: 'Done',
                hideDeclineButton: true,
                onClose: (modal) => {
                    this.modalsManager.done(modal);
                }
            });
        } else {
            window.open(file.url, '_blank');
        }
    }
}