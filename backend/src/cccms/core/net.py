from __future__ import annotations

import ipaddress

from fastapi import Request

from cccms.config import get_settings


def _parse_ip(value: str) -> ipaddress.IPv4Address | ipaddress.IPv6Address | None:
    try:
        return ipaddress.ip_address(value.strip())
    except ValueError:
        return None


def _is_trusted(ip: str, networks: list[ipaddress.IPv4Network | ipaddress.IPv6Network]) -> bool:
    address = _parse_ip(ip)
    if address is None:
        return False
    return any(address in network for network in networks)


def _trusted_networks() -> list[ipaddress.IPv4Network | ipaddress.IPv6Network]:
    result: list[ipaddress.IPv4Network | ipaddress.IPv6Network] = []
    for item in get_settings().trusted_proxies:
        try:
            result.append(ipaddress.ip_network(item, strict=False))
        except ValueError:
            continue
    return result


def client_ip(request: Request) -> str:
    direct = request.client.host if request.client else ""
    networks = _trusted_networks()
    if not networks or not _is_trusted(direct, networks):
        return direct
    forwarded = request.headers.get("x-forwarded-for", "")
    if not forwarded:
        return direct
    hops = [part.strip() for part in forwarded.split(",") if part.strip()]
    for hop in reversed(hops):
        if not _is_trusted(hop, networks):
            return hop
    return hops[0] if hops else direct


def ip_allowed(ip: str, allowlist: list[str]) -> bool:
    address = _parse_ip(ip)
    if address is None:
        return False
    for item in allowlist:
        if item.strip() == "*":
            return True
        if item.strip() == ip:
            return True
        try:
            if address in ipaddress.ip_network(item, strict=False):
                return True
        except ValueError:
            continue
    return False
