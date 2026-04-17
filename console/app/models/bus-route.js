import Model, { attr, belongsTo, hasMany } from '@ember-data/model';
import { computed } from '@ember/object'; // ✅ Fixed: @ember/object use karein
import { format, formatDistanceToNow, isValid } from 'date-fns';

export default class BusRouteModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') company_uuid;
    @attr('string') vendor_uuid;

    /** @attributes */
    @attr('string') country;
    @attr('string') travel_type;
    @attr('string') departure_city;
    @attr('string') arrival_city;
    @attr('string') departure_address;
    @attr('string') arrival_address;
    @attr('number', { defaultValue: 0 }) price;
    @attr('string', { defaultValue: 'Economy' }) route_class;
    @attr('string') departure_time;
    //@attr('array') operating_days; // Ensure transform exists or use 'raw'
    @attr('raw') operating_days;
    @attr('raw') advanced_schedule;

    /** @relationships */
    @belongsTo('vendor', { async: true, inverse: null }) vendor;
    //@hasMany('file') files;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    @computed('updated_at') get updatedAgo() {
        if (!isValid(this.updated_at)) return '-';
        return formatDistanceToNow(this.updated_at);
    }

    @computed('updated_at') get updatedAt() {
        if (!isValid(this.updated_at)) return '-';
        return format(this.updated_at, 'PPP p');
    }

    @computed('created_at') get createdAt() {
        if (!isValid(this.created_at)) return '-';
        return format(this.created_at, 'PPP p');
    }
}