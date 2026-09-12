# Logistics Role Pages Design

Dispatchers and riders use separate ERP pages. `/erp/logistics/shipments` is restricted to delivery-management permission and provides assignment and proof approval. `/erp/logistics/deliveries` is restricted to rider-operation permission and shows only the authenticated rider's assigned legs with operational actions and addresses.

The API continues to enforce assignment ownership and proof approval permissions; routes and sidebar visibility are only the UI boundary. The existing shipment/leg data model is reused.
