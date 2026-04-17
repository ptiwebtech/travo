import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class PlacePanelDetailsComponent extends Component {
    /**
     * Inject the modalsManager service to show the image viewer
     */
    @service modalsManager;

    /**
     * Action to view a file/image
     * * @param {Object} file 
     */
    @action
    viewFile(file) {
        console.log('Viewing file:', file.original_filename);

        // Check if the file is an image
        const isImage = file.content_type && file.content_type.startsWith('image/');

        if (isImage) {
            this.modalsManager.show('modals/image-viewer', {
                title: file.original_filename,
                imageSource: file.url,
                acceptButtonText: 'Done',
                hideDeclineButton: true,
                confirm: (modal) => {
                    return modal.done();
                }
            });
        } else {
            // If not an image (PDF etc), open in new tab
            window.open(file.url, '_blank');
        }
    }
}
