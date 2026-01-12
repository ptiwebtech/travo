import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { pluralize } from 'ember-inflector';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

function isUuid(str) {
    if (typeof str !== 'string') {
        return false;
    }

    const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    return uuidRegex.test(str);
}

export default class AvatarPickerComponent extends Component {
    @service store;
    @tracked model;
    @tracked type;
    @tracked endpoint;



    vehicleTypes = [
        { value: '3 Door Hatchback', url: '/images/3-door-hatchback.png', label: '3 Door Hatchback' },
        { value: '4 Door Truck', url: '/images/4-door-truck.png', label: '4 Door Truck' },
        { value: '5 Door Hatchback', url: '/images/5-door-hatchback.png', label: '5 Door Hatchback' },
        { value: 'Ambulance', url: '/images/ambulance.png', label: 'Ambulance' },
        { value: 'Convertible', url: '/images/convertible.png', label: 'Convertible' },
        { value: 'Coupe', url: '/images/coupe.png', label: 'Coupe' },
        { value: 'Electric Car', url: '/images/electric-car.png', label: 'Electric Car' },
        { value: 'Fastback', url: '/images/fastback.png', label: 'Fastback' },
        { value: 'Full Size Suv', url: '/images/full-size-suv.png', label: 'Full Size Suv' },
        { value: 'Hot Hatch', url: '/images/Hot-Hatch.png', label: 'Hot Hatch' },
        { value: 'Large Ambulance', url: '/images/Large-Ambulance.png', label: 'Large Ambulance' },
        { value: 'Light Commercial Truck', url: '/images/Light-Commercial-Truck.png', label: 'Light Commercial Truck' },
        { value: 'Light Commercial Van', url: '/images/Light-Commercial-Van.png', label: 'Light Commercial Van' },
        { value: 'Large Commercial Truck', url: '/images/Large-Commercial-Truck.png', label: 'Large Commercial Truck' },
        { value: 'Large Commercial Van', url: '/images/Large-Commercial-Van.png', label: 'Large Commercial Van' },
        { value: 'Limousine', url: '/images/Limousine.png', label: 'Limousine' },
        { value: 'Mid Size Suv', url: '/images/Mid-Size-Suv.png', label: 'Mid Size Suv' },
        { value: 'Mini Bus', url: '/images/Mini-Bus.png', label: 'Mini Bus' },
        { value: 'Mini Van', url: '/images/Mini-Van.png', label: 'Mini Van' },
        { value: 'Bus', url: '/images/bus.png', label: 'Bus' },
        { value: 'Large Coach', url: '/images/Large-Coach.png', label: 'Large Coach' },
        { value: 'Muscle Car', url: '/images/Muscle-Car.png', label: 'Muscle Car' },
        { value: 'Police 1', url: '/images/Police-1.png', label: 'Police 1' },
        { value: 'Police 2', url: '/images/Police-2.png', label: 'Police 2' },
        { value: 'Roadster', url: '/images/Roadster.png', label: 'Roadster' },
        { value: 'Sedan', url: '/images/Sedan.png', label: 'Sedan' },
        { value: 'Small 3 Door Hatchback', url: '/images/Small-3-Door-Hatchback.png', label: 'Small 3 Door Hatchback' },
        { value: 'Small 5 Door Hatchback', url: '/images/Small-5-Door-Hatchback.png', label: 'Small 5 Door Hatchback' },
        { value: 'Sportscar', url: '/images/Sportscar.png', label: 'Sportscar' },
        { value: 'Station Wagon', url: '/images/Station-Wagon.png', label: 'Station Wagon' },
        { value: 'Taxi', url: '/images/Taxi.png', label: 'Taxi' },
        { value: 'Delivery Van', url: '/images/delivery-van.png', label: 'Delivery Van' },
        { value: 'Delivery Bike', url: '/images/delivery-bike.png', label: 'Delivery Bike' },
        { value: 'Delivery Truck', url: '/images/delivery-truck.png', label: 'Delivery Truck' },
        { value: 'Delivery Motorbike', url: '/images/delivery-motorbike.png', label: 'Delivery Motorbike' },
        { value: 'Tow Truck', url: '/images/Tow-Truck.png', label: 'Tow Truck' },
        { value: 'Cargo Boat', url: '/images/cargo-boat.png', label: 'Cargo Boat' },
        { value: 'Small Drone', url: '/images/Small-Drone.png', label: 'Small Drone' },
        { value: 'Large Drone', url: '/images/Large-Drone.png', label: 'Large Drone' },
        { value: 'Airplane', url: '/images/airplane.png', label: 'Airplane' },
        { value: 'Helicopter', url: '/images/Helicopter.png', label: 'Helicopter' },
        { value: 'Cement Truck', url: '/images/cement-truck.png', label: 'Cement Truck' },
        { value: 'Rubbish Truck', url: '/images/Rubbish-Truck.png', label: 'Rubbish Truck' },
        { value: 'Fire Engine', url: '/images/fire-engine.png', label: 'Fire Engine' },
        { value: 'Small Plane', url: '/images/Small-Plane.png', label: 'Small Plane' },
        { value: 'Private Jet', url: '/images/Private-Jet.png', label: 'Private Jet' }
    ];



    constructor(owner, { model, endpoint }) {
        super(...arguments);

        this.model = model;
        this.type = getModelName(model);
        this.endpoint = endpoint ?? `${pluralize(this.type)}/avatars`;
    }

    /**
     * Set the selected avatar
     *
     * @param {String} url
     */
    @action selectAvatar(event) {
        const avatarType = event.target.value;
        if (!this.vehicleTypes || !Array.isArray(this.vehicleTypes)) {
            return;
        }
        const vehicle = this.vehicleTypes.find((v) => v.value === avatarType);
        if (!vehicle) {
            return;
        }
        const url = vehicle?.url || null;
        if (isUuid(url)) {
            return this.store.findRecord('file', url).then((file) => {
                this.model.set('avatar_custom_url', file.url);
                this.model.set('avatar_url', file.id);
                this.model.set('avatar_value', avatarType);
                if (typeof this.args.onSelect === 'function') {
                    this.args.onSelect(this.model, file.url);
                }
            });
        }
        // default url
        this.model.set('avatar_url', url);
        this.model.set('avatar_value', avatarType);
        this.model.set('avatar_custom_url', null);

        if (typeof this.args.onSelect === 'function') {
            this.args.onSelect(this.model, url);
        }
    }
}
