# Streetwise Portrait Upload API

## Overview
Secure API endpoint for uploading character portraits from the Owlbear Rodeo Streetwise extension.

## Endpoint

```
POST https://urchinator.notoriety.co.uk/api/upload-portrait.php
```

## Authentication

Include the API key in the request headers:

```
X-API-Key: sw_live_portrait_upload_[secret]
```

The API key is stored in the `.env` file as `PORTRAIT_UPLOAD_API_KEY`.

## Request Format

**Headers:**
```
Content-Type: application/json
X-API-Key: [your-api-key]
```

**Body:**
```json
{
  "image": "data:image/jpeg;base64,/9j/4AAQSkZJRg...",
  "characterId": "unique-character-id",
  "characterName": "Character Name (optional)"
}
```

**Parameters:**
- `image` (required): Base64-encoded data URI of the image
  - Supported formats: JPEG, PNG, GIF, WebP
  - Will be converted to optimized JPEG
- `characterId` (required): Unique identifier for the character
  - Alphanumeric, hyphens, and underscores only
  - Used to organize uploads
- `characterName` (optional): Human-readable character name
  - Used for logging/debugging

## Response Format

**Success (200):**
```json
{
  "success": true,
  "url": "https://urchinator.notoriety.co.uk/uploads/portraits/char-123/portrait-1234567890.jpg",
  "characterId": "char-123",
  "size": 45678,
  "dimensions": {
    "width": 512,
    "height": 512
  }
}
```

**Error (400/401/500):**
```json
{
  "error": "Error message description"
}
```

## Image Processing

The API automatically:
1. **Validates** the image format and data
2. **Crops** to a square aspect ratio (center crop)
3. **Resizes** to 512x512 pixels (optimal for VTT tokens)
4. **Optimizes** JPEG quality to 85% for good quality/size balance
5. **Replaces** old portraits (only keeps the latest for each character)

## Security Features

- ✅ API key authentication via headers
- ✅ Input sanitization (characterId)
- ✅ CORS enabled for cross-origin requests
- ✅ File type validation
- ✅ Base64 validation
- ✅ Directory traversal prevention
- ✅ Old file cleanup

## Error Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 400 | Bad Request (invalid image, missing fields) |
| 401 | Unauthorized (invalid API key) |
| 405 | Method Not Allowed (must use POST) |
| 500 | Server Error (failed to save) |

## Usage Example (JavaScript)

```javascript
async function uploadPortrait(imageDataUri, characterId, characterName) {
  const response = await fetch('https://urchinator.notoriety.co.uk/api/upload-portrait.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': 'sw_live_portrait_upload_[secret]'
    },
    body: JSON.stringify({
      image: imageDataUri,
      characterId: characterId,
      characterName: characterName
    })
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.error);
  }

  const result = await response.json();
  return result.url; // https://urchinator.notoriety.co.uk/uploads/portraits/...
}
```

## Storage Structure

```
public/
  uploads/
    portraits/
      {characterId}/
        portrait-{timestamp}.jpg
```

Old portraits are automatically deleted when a new one is uploaded for the same character.

## Development Setup

1. Ensure GD library is installed for PHP
2. Set `PORTRAIT_UPLOAD_API_KEY` in `.env`
3. Ensure `public/uploads/portraits/` is writable (755)
4. Keep `.gitignore` in portraits folder to prevent committing uploads

## Notes

- Maximum upload size limited by PHP's `upload_max_filesize` and `post_max_size`
- Recommended max: 10MB per image
- Uploads folder should NOT be committed to git
- API key should be stored securely and not exposed in client code
