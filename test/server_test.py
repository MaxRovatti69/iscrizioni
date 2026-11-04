import unittest
from unittest.mock import patch, MagicMock
from urllib.error import URLError
import server


class CounterTests(unittest.TestCase):
    def test_only_supported_endu_sources_are_allowed(self):
        self.assertEqual(server.validate_source(server.BASE + 'entrants.json'), server.BASE + 'entrants.json')
        for url in ['http://localhost/entrants.json', server.BASE + 'counters.json', server.BASE + 'entrants.json?url=x']:
            with self.assertRaises(ValueError):
                server.load_counters(url)

    def test_event_url_selects_its_own_source(self):
        url = server.BASE.replace('00102708', '00102709') + 'entrants.json'
        with patch('server.read_counter', return_value=('entrants.json', {'data': {'data': [{'count': '12'}]}})) as read:
            payload = server.load_counters(url)
        read.assert_called_once_with('entrants.json', base=url.removesuffix('entrants.json'))
        self.assertEqual(payload['eventId'], '00102709')

    def test_only_entrants_is_requested(self):
        with patch('server.read_counter', return_value=('entrants.json', {'data': {'data': [{'count': '3403'}]}})) as read:
            payload = server.load_counters()
        read.assert_called_once_with('entrants.json')
        self.assertEqual(list(payload['sources']), ['entrants.json'])

    def test_json_response_preserved(self):
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b'{"count":42}'
        with patch('server.urlopen', return_value=response) as request:
            name, result = server.read_counter('entrants.json')
        self.assertEqual(result, {'data': {'count': 42}})
        self.assertEqual(name, 'entrants.json')
        self.assertEqual(request.call_args.args[0], server.BASE + 'entrants.json')

    def test_network_failure_is_explicit(self):
        with patch('server.urlopen', side_effect=URLError('blocked')):
            _, result = server.read_counter('entrants.json')
        self.assertIn('error', result)
        self.assertNotIn('data', result)

    def test_invalid_json_is_not_a_zero_counter(self):
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b'<html>error</html>'
        with patch('server.urlopen', return_value=response):
            _, result = server.read_counter('entrants.json')
        self.assertIn('error', result)
