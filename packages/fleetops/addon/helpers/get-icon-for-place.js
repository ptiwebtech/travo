import { helper } from '@ember/component/helper';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';

const placeImages = {
  "Bus Station": "/images/Bus_Station.png",
  "Railway Station": "/images/Railway_Station.png",
  "Ferry Terminal": "/images/Ferry_Terminal.png",
  "Taxi Stand": "/images/Taxi_Stand.png",
  "Airport": "/images/Airport.png",
  "Seaport": "/images/Seaport.png",
  "Bus Terminal": "/images/Private_Bus_Terminal.png",
  "Car Rental Location": "/images/Car_Rental_Location.png",
  "Bicycle Sharing Station": "/images/Bicycle_Sharing_Station.png",
  "Korope Park": "/images/minibus.png",
  "Heliport": "/images/heliport.png",
  "Drone port": "/images/drone.png",
  "Pickup/Drop-off Point": "/images/PickupDrop-off-Point.png",
  "Border Crossing": "/images/Border_Crossing.png",
  "Parking Lot/Garage": "/images/Parking_Lot_Garage.png",
  "Service Station": "/images/Service_Station.png",
  "Cargo Terminal": "/images/Cargo_Terminal.png",
  "Rest Stops/Service Area": "/images/Rest_Stops_Service_Area.png",
  "Courier Service Depot": "/images/Courier_Service_Depot.png",
  "Freight Forwarding Center": "/images/Freight_Forwarding_Center.png",
  "Warehousing Location": "/images/Warehousing_Location.png",
  "Truck Park": "/images/Truck_Park.png",
  "Hotel and Accommodation": "/images/Hotel_and_Accomodation.png",
  "Serviced Apartment": "/images/Serviced_Apartment.png",
  "Conference Center": "/images/Conference_Center.png",
  "Guesthouse": "/images/Guesthouse.png",
  "Event Space": "/images/event_space.png",
  "Serviced Office": "/images/Serviced_Office.png",
  "Storefront": "/images/Storefront.png",
  "Food Takeaway": "/images/Food_Takeaway.png",
  "Restaurant": "/images/Restaurant.png",
  "Supermarket": "/images/Supermarket.png",
  "Auto Repair Shop": "/images/Auto_Repair_Shop.png",
  "Laundromat": "/images/Laundromat.png",
  "Beauty Salon": "/images/Beauty_Salon.png",
  "Barber Shop": "/images/Barber_Shop.png",
  "Pharmacy": "/images/Pharmacy.png",
  "Gym": "/images/Gym.png",
  "Police Station": "/images/Police_Station.png",
  "Clinic": "/images/Clinic.png",
  "Hospital": "/images/Hospital.png",
  "Fire Station": "/images/Fire_Station.png",
};

export default helper(function getIconForPlace([placeType]) {
  const iconUrl = placeImages[placeType] || '/engines-dist/images/building-marker.png';

  return leafletIcon({
    iconUrl,
    iconSize: [16, 16],
  });
});
