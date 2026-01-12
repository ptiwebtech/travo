import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class DisplayPlaceComponent extends Component {
    @tracked ref;

    @action setupComponent(element) {
        this.ref = element;
    }

    get fullAddress() {
        const place = this.args.place?.place || this.args.place;
        if (!place) return null;

        const parts = [
            place.name,
            place.street1,
            place.street2,
            place.city,
            place.province,
            place.postal_code,
            place.neighborhood,
            place.district,
            (!place.street1 ? place.building : null),
        ].filter(Boolean);

        return parts.join(', ');
    }

    // ✅ Safe Google Maps URL
    get googleMapsUrl() {
        if (!this.fullAddress) return null;
        return `https://www.google.com/maps/search/${encodeURIComponent(this.fullAddress)}`;
    }
}
