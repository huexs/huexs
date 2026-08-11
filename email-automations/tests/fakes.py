"""Dobles de prova dels serveis de Google.

Reprodueixen la forma encadenada de `googleapiclient` (`.users().messages()
.list(...).execute()`) sense cap dependència ni accés a la xarxa.
"""

from __future__ import annotations

from typing import Any, Callable


class _Call:
    def __init__(self, result: Any, kwargs: dict[str, Any]) -> None:
        self._result = result
        self.kwargs = kwargs

    def execute(self) -> Any:
        if isinstance(self._result, Exception):
            raise self._result
        return self._result


class FakeEndpoint:
    """Endpoint amb respostes en cua i registre de crides."""

    def __init__(self, responses: list[Any] | None = None) -> None:
        self.responses = list(responses or [])
        self.calls: list[dict[str, Any]] = []
        self.handler: Callable[[dict[str, Any]], Any] | None = None

    def __call__(self, **kwargs: Any) -> _Call:
        self.calls.append(kwargs)
        if self.handler is not None:
            return _Call(self.handler(kwargs), kwargs)
        if not self.responses:
            raise AssertionError("Crida inesperada a l'endpoint fals")
        return _Call(self.responses.pop(0), kwargs)


class FakeGmailService:
    def __init__(self) -> None:
        self.labels_list = FakeEndpoint()
        self.messages_list = FakeEndpoint()
        self.messages_get = FakeEndpoint()
        self.messages_send = FakeEndpoint()
        self.attachments_get = FakeEndpoint()

    def users(self) -> "FakeGmailService":
        return self

    def labels(self) -> "_LabelsResource":
        return _LabelsResource(self)

    def messages(self) -> "_MessagesResource":
        return _MessagesResource(self)


class _LabelsResource:
    def __init__(self, service: FakeGmailService) -> None:
        self.list = service.labels_list


class _MessagesResource:
    def __init__(self, service: FakeGmailService) -> None:
        self._service = service
        self.list = service.messages_list
        self.get = service.messages_get
        self.send = service.messages_send

    def attachments(self) -> "_AttachmentsResource":
        return _AttachmentsResource(self._service)


class _AttachmentsResource:
    def __init__(self, service: FakeGmailService) -> None:
        self.get = service.attachments_get


class FakeDriveService:
    def __init__(self) -> None:
        self.files_list = FakeEndpoint()
        self.files_create = FakeEndpoint()

    def files(self) -> "_FilesResource":
        return _FilesResource(self)


class _FilesResource:
    def __init__(self, service: FakeDriveService) -> None:
        self.list = service.files_list
        self.create = service.files_create


class FakeYouTubeService:
    def __init__(self) -> None:
        self.subscriptions_list = FakeEndpoint()
        self.channels_list = FakeEndpoint()
        self.playlist_items_list = FakeEndpoint()

    def subscriptions(self) -> "_SimpleResource":
        return _SimpleResource(self.subscriptions_list)

    def channels(self) -> "_SimpleResource":
        return _SimpleResource(self.channels_list)

    def playlistItems(self) -> "_SimpleResource":  # noqa: N802 - forma de l'API
        return _SimpleResource(self.playlist_items_list)


class _SimpleResource:
    def __init__(self, endpoint: FakeEndpoint) -> None:
        self.list = endpoint
