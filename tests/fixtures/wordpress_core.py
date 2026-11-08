"""Exercise the real Python core from PHP with deterministic, offline HTTP data."""
import base64
import io
import sys
import time
from pathlib import Path
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
import commons_image_dl as core
from PIL import Image

buffer = io.BytesIO()
Image.new('RGB', (4, 4), 'red').save(buffer, format='PNG')
PAYLOAD = buffer.getvalue()
PAGE = {'pageid': 123, 'title': 'File:Example.png', 'imageinfo': [{
    'url': 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Example.png',
    'descriptionurl': 'https://commons.wikimedia.org/wiki/File:Example.png',
    'mime': 'image/png', 'width': 4, 'height': 4, 'sha1': 'fixture',
    'extmetadata': {'Artist': {'value': 'Example author'},
                    'ImageDescription': {'value': 'Original description'},
                    'LicenseShortName': {'value': 'CC BY-SA 4.0'}}}]}


class OfflineClient(core.Client):
    def api(self, **params):
        query = params.get('gsrsearch')
        if query == '__timeout__':
            time.sleep(5)
        if query == '__failure__':
            raise core.DownloadError('Fixture API failure')
        if params.get('pageids') == 404:
            return {'query': {'pages': []}}
        if params.get('prop') == 'categories':
            return {'query': {'pages': [{'pageid': 123, 'categories': [{'title': 'Category:Penang'}]}]}}
        return {'query': {'pages': [PAGE]}}

    def get(self, url, **kwargs):
        response = Mock()
        response.headers = {'Content-Type': 'image/png'}
        response.iter_content.return_value = [PAYLOAD]
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        return response


core.Client = OfflineClient
sys.exit(core.main())
