import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';

export default class BusRouteSerializer extends ApplicationSerializer {
    primaryKey = 'uuid';
    // Baaki sab abhi ke liye hata kar check karein
}