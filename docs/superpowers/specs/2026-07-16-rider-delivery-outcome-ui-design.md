# Rider Delivery Outcome UI Design

For an in-transit delivery, the rider first chooses one outcome: **Delivered successfully** or **Couldn't deliver**. No upload field is shown before that choice.

**Delivered successfully** shows one clearly labeled delivery-photo panel and submit button. **Couldn't deliver** shows an amber issue panel with reason, required attempt photo, optional note, and report button. The two forms are never visible together.

Switching outcomes clears the hidden workflow's selected file and issue fields. Existing endpoints, validation, and dispatcher-controlled cancellation remain unchanged.
