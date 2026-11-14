(function() {
  var DBB_AREAS = [
    {
      label: 'Area B (DBB-B)',
      code: 'DBB-B',
      barangays: [
        { label: 'Burol I', name: 'Burol I' },
        { label: 'Burol II', name: 'Burol II' },
      ],
    },
    {
      label: 'Area C (DBB-C)',
      code: 'DBB-C',
      barangays: [
        { label: 'Parts of Burol III', name: 'Burol III', partial: true },
        { label: 'San Andres I', name: 'San Andres I' },
        { label: 'San Andres II', name: 'San Andres II' },
        { label: 'San Roque', name: 'San Roque' },
        { label: 'San Simon', name: 'San Simon' },
        { label: 'Sta. Cristina I', name: 'Santa Cristina I' },
        { label: 'Sta. Cristina II', name: 'Santa Cristina II' },
        { label: 'Victoria Reyes', name: 'Victoria Reyes' },
      ],
    },
    {
      label: 'Area D (DBB-D)',
      code: 'DBB-D',
      barangays: [
        { label: 'Luzviminda I', name: 'Luzviminda I' },
        { label: 'Luzviminda II', name: 'Luzviminda II' },
        { label: 'San Nicolas I', name: 'San Nicolas I' },
        { label: 'San Nicolas II', name: 'San Nicolas II' },
        { label: 'San Mateo', name: 'San Mateo' },
        { label: 'Bautista Property (part of Sampaloc IV)', name: 'Sampaloc IV', partial: true },
      ],
    },
    {
      label: 'Area E (DBB-E)',
      code: 'DBB-E',
      barangays: [
        { label: 'Emmanuel Bergado I', name: 'Emmanuel Bergado I' },
        { label: 'Emmanuel Bergado II', name: 'Emmanuel Bergado II' },
        { label: 'San Lorenzo Ruiz I', name: 'San Lorenzo Ruiz I' },
        { label: 'San Lorenzo Ruiz II', name: 'San Lorenzo Ruiz II' },
        { label: 'San Luis I', name: 'San Luis I' },
        { label: 'San Luis II', name: 'San Luis II' },
        { label: 'Sta. Cruz I', name: 'Santa Cruz I' },
        { label: 'Sta. Cruz II', name: 'Santa Cruz II' },
      ],
    },
    {
      label: 'Area F (DBB-F)',
      code: 'DBB-F',
      barangays: [
        { label: 'Fatima I', name: 'Fatima I' },
        { label: 'Fatima II', name: 'Fatima II' },
        { label: 'Fatima III', name: 'Fatima III' },
      ],
    },
    {
      label: 'Area G (DBB-G)',
      code: 'DBB-G',
      barangays: [
        { label: 'San Isidro Labrador I', name: 'San Isidro Labrador I' },
        { label: 'San Isidro Labrador II', name: 'San Isidro Labrador II' },
      ],
    },
  ];

  function normalizeSearchText(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
  }

  function getAreaSuggestions(query) {
    var normalizedQuery = normalizeSearchText(query);
    if (normalizedQuery.length < 3) return [];

    return DBB_AREAS.filter(function(area) {
      return [area.label, area.code].some(function(term) {
        return normalizeSearchText(term).indexOf(normalizedQuery) !== -1;
      });
    }).map(function(area) {
      return {
        kind: 'area',
        type_label: 'DBB Area',
        label: area.label,
        detail: area.barangays.map(function(barangay) { return barangay.label; }).join(' · '),
        barangays: area.barangays,
      };
    });
  }

  function initializeSearch(wrapper) {
    var input = wrapper.querySelector('input[type="search"]');
    var resultsElement = wrapper.querySelector('.dcvs-place-results');
    var statusElement = document.querySelector(wrapper.getAttribute('data-status-target'));
    var mapElement = document.getElementById(wrapper.getAttribute('data-map-target'));
    var endpoint = wrapper.getAttribute('data-geocode-endpoint');
    var portal = wrapper.getAttribute('data-portal');
    var selectionMode = wrapper.getAttribute('data-selection-mode') || 'focus';
    var localSuggestions = [];
    var placeResults = [];
    var activeIndex = -1;
    var pendingBarangay = null;
    var pendingPlace = null;
    var map = null;
    var mapMarker = null;
    var timer = null;
    var requestId = 0;
    var controller = null;

    if (!input || !resultsElement || !statusElement || !endpoint || !portal) return;

    function setStatus(message) {
      statusElement.textContent = message;
    }

    function closeResults() {
      resultsElement.hidden = true;
      resultsElement.replaceChildren();
      input.setAttribute('aria-expanded', 'false');
      activeIndex = -1;
    }

    function getBarangaySuggestions(query) {
      if (selectionMode === 'pin') {
        var select = document.getElementById('location-barangay');
        if (!select) return [];
        var normalizedQuery = normalizeSearchText(query);
        if (normalizedQuery.length < 2) return [];
        return Array.prototype.slice.call(select.options).filter(function(option) {
          return option.value && normalizeSearchText(option.textContent).indexOf(normalizedQuery) !== -1;
        }).map(function(option) {
          return {
            kind: 'barangay',
            label: option.textContent.trim(),
            name: option.textContent.trim(),
            code: option.value,
            detail: 'Show this barangay boundary'
          };
        }).slice(0, 8);
      }
      var overlay = window.MapCN && window.MapCN.barangayOverlay;
      if (!overlay || typeof overlay.suggest !== 'function') return [];
      var suggestions = overlay.suggest(query);
      if (!Array.isArray(suggestions)) return [];
      return suggestions.map(function(suggestion) {
        var name = suggestion && (suggestion.name || suggestion.label);
        if (typeof name !== 'string' || !name.trim()) return null;
        name = name.trim();
        return {
          kind: 'barangay',
          label: name,
          name: name,
          detail: 'Show this barangay boundary'
        };
      }).filter(function(suggestion) {
        return suggestion !== null;
      });
    }

    function getLandmarkSuggestions(query) {
      var landmarks = window.DCVSMapLandmarks;
      if (!landmarks || typeof landmarks.suggest !== 'function') return [];
      var suggestions = landmarks.suggest(query, 5);
      return Array.isArray(suggestions) ? suggestions : [];
    }

    function createOption(label, detail, kind, value, index, parent) {
      var option = document.createElement('button');
      option.type = 'button';
      option.className = 'dcvs-place-option';
      if (kind === 'area') option.classList.add('dcvs-place-option-area');
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');

      var title = document.createElement('span');
      title.className = 'dcvs-place-option-title';
      title.textContent = label;
      var subtitle = document.createElement('span');
      subtitle.className = 'dcvs-place-option-detail';
      subtitle.textContent = detail;
      var type = document.createElement('span');
      type.className = 'dcvs-place-option-type';
      type.textContent = kind === 'barangay' ? 'Barangay' : (value.type_label || 'Place');
      if ((kind === 'place' || kind === 'landmark') && value.approximate) type.textContent += ' · approx.';

      option.appendChild(title);
      option.appendChild(subtitle);
      option.appendChild(type);
      option.addEventListener('click', function() {
        if (kind === 'barangay') selectBarangay(value);
        else if (kind === 'area') selectArea(value);
        else selectPlace(value);
      });
      parent.appendChild(option);
      return index;
    }

    function renderSearchMessage(message) {
      resultsElement.replaceChildren();
      var state = document.createElement('div');
      state.className = 'dcvs-place-search-message';
      state.setAttribute('role', 'status');
      state.textContent = message;
      resultsElement.appendChild(state);
      resultsElement.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      activeIndex = -1;
      setStatus(message);
    }

    function renderResults() {
      resultsElement.replaceChildren();
      activeIndex = -1;
      var index = 0;
      var groups = { Places: [], Streets: [], Barangays: [] };
      localSuggestions.forEach(function(suggestion) {
        groups[suggestion.kind === 'area' || suggestion.kind === 'barangay' ? 'Barangays' : 'Places'].push({
          value: suggestion,
          kind: suggestion.kind || 'landmark',
          label: suggestion.label || suggestion.name,
          detail: suggestion.detail || 'Show this barangay boundary'
        });
      });
      placeResults.forEach(function(place) {
        var type = String(place.type_label || '').toLowerCase();
        var group = type.indexOf('street') !== -1 || type.indexOf('address') !== -1
          ? 'Streets'
          : (type.indexOf('area') !== -1 || type.indexOf('barangay') !== -1 || place.psgc_code
            ? 'Barangays' : 'Places');
        var details = [];
        if (place.context) details.push(place.context);
        if (place.barangay) details.push('Barangay ' + place.barangay);
        groups[group].push({
          value: place,
          kind: 'place',
          label: place.name || place.label,
          detail: details.length ? details.join(' · ') : place.label
        });
      });

      Object.keys(groups).forEach(function(groupName) {
        if (!groups[groupName].length) return;
        var group = document.createElement('div');
        group.className = 'dcvs-place-result-group';
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', groupName);
        var heading = document.createElement('div');
        heading.className = 'dcvs-place-group-heading';
        heading.setAttribute('role', 'presentation');
        heading.textContent = groupName;
        group.appendChild(heading);
        groups[groupName].forEach(function(item) {
          index = createOption(item.label, item.detail, item.kind, item.value, index, group) + 1;
        });
        resultsElement.appendChild(group);
      });

      resultsElement.hidden = index === 0;
      input.setAttribute('aria-expanded', index ? 'true' : 'false');
      if (!index) {
        var query = input.value.trim();
        if (query.length >= 3) {
          renderSearchMessage('No results');
        }
      }
    }

    function searchPlace(query, currentRequestId) {
      var url = new URL(endpoint, window.location.href);
      url.searchParams.set('portal', portal);
      url.searchParams.set('q', query);
      url.searchParams.set('mode', selectionMode);
      controller = typeof AbortController === 'function' ? new AbortController() : null;
      var options = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
      if (controller) options.signal = controller.signal;

      return fetch(url.toString(), options)
        .then(function(response) {
          return response.json().then(function(payload) {
            if (!response.ok || !payload.success) {
              throw new Error(payload.message || 'Place search could not be completed.');
            }
            return payload;
          });
        })
        .then(function(payload) {
          if (currentRequestId !== requestId) return;
          placeResults = Array.isArray(payload.results) ? payload.results : [];
          renderResults();
          if (placeResults.length) {
            setStatus(portal === 'resident'
              ? 'Choose the matching location from the list.'
              : 'Select a matching place. Its coordinates will be checked against Dasmariñas barangay boundaries.');
          } else if (localSuggestions.length) {
            setStatus(portal === 'resident'
              ? (localSuggestions.some(function(suggestion) { return suggestion.kind === 'area'; })
                ? 'Choose a DBB area to view its barangays, or search for a more specific location.'
                : 'Choose a barangay, or search for a more specific location.')
              : (localSuggestions.some(function(suggestion) { return suggestion.kind === 'landmark'; })
                ? 'Choose a mapped landmark, or try a more specific place name.'
                : 'Select a barangay boundary or try a more specific place name.'));
          } else {
            setStatus(portal === 'resident'
              ? 'No matching place was found in Dasmariñas. Try a specific address or landmark, drag the pin, or click the map.'
              : 'No matching address, place, or barangay found in Dasmariñas.');
          }
        })
        .catch(function(error) {
          if (error.name === 'AbortError' || currentRequestId !== requestId) return;
          placeResults = [];
          renderResults();
          setStatus(portal === 'resident'
            ? (localSuggestions.length
              ? (localSuggestions.some(function(suggestion) { return suggestion.kind === 'area'; })
                ? "Place search isn't available right now, but you can still choose a DBB area, drag the pin, or click the map."
                : (localSuggestions.some(function(suggestion) { return suggestion.kind === 'landmark'; })
                  ? "Place search isn't available right now, but you can still choose a mapped landmark."
                  : "Place search isn't available right now, but you can still choose a barangay boundary, drag the pin, or click the map."))
              : "Location search isn't available right now. You can drag the pin, click the map, or enter directions below.")
            : (localSuggestions.some(function(suggestion) { return suggestion.kind === 'landmark'; })
              ? 'Address search is unavailable, but you can still choose a mapped landmark or barangay.'
              : (error.message || 'Place search could not be completed.')));
          console.error('Map place search failed:', error);
        });
    }

    function selectBarangay(value) {
      var name = typeof value === 'string' ? value : value.name;
      var label = typeof value === 'string' ? name : (value.label || name);
      requestId++;
      clearTimeout(timer);
      if (controller) controller.abort();
      input.value = label;
      placeResults = [];
      closeResults();
      pendingPlace = null;
      if (mapMarker) mapMarker.hidden = true;
      if (selectionMode === 'pin') {
        pendingBarangay = null;
        if (mapElement) {
          mapElement.dispatchEvent(new CustomEvent('dcvs:barangay-search', {
            detail: { name: name, code: typeof value === 'string' ? '' : value.code }
          }));
          setStatus('Showing ' + name + '. Drag the pin or click the map to place the incident pin.');
        } else {
          setStatus('The report map is unavailable. Reload the page and try again.');
        }
        return;
      }
      var overlay = window.MapCN && window.MapCN.barangayOverlay;
      var result = overlay && typeof overlay.search === 'function' ? overlay.search(name) : null;
      if (result === null) {
        pendingBarangay = value;
        setStatus('Map data is loading. Your search will run when it is ready.');
      } else if (result) {
        pendingBarangay = null;
        pendingPlace = null;
        if (mapMarker) mapMarker.hidden = true;
        setStatus(value.selectionNotice || 'Showing ' + name + ' on the map.');
      } else {
        pendingBarangay = null;
        setStatus('No barangay found matching "' + label + '".');
      }
    }

    function selectArea(area) {
      requestId++;
      clearTimeout(timer);
      if (controller) controller.abort();
      input.value = area.label;
      placeResults = [];
      pendingBarangay = null;
      pendingPlace = null;
      if (mapMarker) mapMarker.hidden = true;
      localSuggestions = area.barangays.map(function(barangay) {
        return {
          kind: 'barangay',
          label: barangay.label,
          name: barangay.name,
          detail: barangay.partial
            ? 'Part of this barangay · show the larger boundary'
            : 'Show this barangay boundary',
          selectionNotice: barangay.partial
            ? 'Showing the larger ' + barangay.name + ' boundary. This DBB area covers only part of it; place the pin on the exact incident spot.'
            : 'Showing ' + barangay.name + ' on the map. Place the pin on the exact incident spot.',
        };
      });
      renderResults();
      setStatus('Choose the barangay where the incident happened, then place the pin on the exact spot.');
    }

    function updateMapMarker() {
      if (!map || !mapMarker || !pendingPlace) return;
      var point = map.project(pendingPlace.coordinates);
      mapMarker.style.left = point.x + 'px';
      mapMarker.style.top = point.y + 'px';
      mapMarker.hidden = false;
    }

    function focusPlace(place) {
      if (!map) {
        pendingPlace = place;
        setStatus('Map is loading. The selected place will appear when it is ready.');
        return;
      }
      pendingPlace = place;
      if (!mapMarker) {
        mapMarker = document.createElement('span');
        mapMarker.className = 'dcvs-map-search-marker';
        mapMarker.setAttribute('aria-hidden', 'true');
        mapMarker.innerHTML = '<span></span>';
        mapMarker.hidden = true;
        map.getContainer().appendChild(mapMarker);
        map.on('render', updateMapMarker);
      }
      var zoom = Math.min(19, Math.max(17, map.getZoom()));
      map.flyTo({ center: place.coordinates, zoom: zoom, duration: 700 });
      updateMapMarker();
      setStatus(
        'Showing ' + place.label + ' in ' + place.barangay +
        (place.approximate ? ' · approximate point' : '') + ' (' +
        Number(place.coordinates[1]).toFixed(6) + ', ' +
        Number(place.coordinates[0]).toFixed(6) + ').'
      );
    }

    function selectPlace(place) {
      requestId++;
      clearTimeout(timer);
      if (controller) controller.abort();
      input.value = place.label;
      localSuggestions = [];
      placeResults = [];
      pendingBarangay = null;
      closeResults();
      if (selectionMode === 'pin') {
        if (mapElement) {
          mapElement.dispatchEvent(new CustomEvent('dcvs:place-selected', { detail: place }));
          setStatus(portal === 'resident'
            ? (place.approximate
              ? 'This result is approximate. Check the satellite imagery and drag the pin to the exact incident spot.'
              : 'Location selected. Check the pin and drag it or click the map if needed.')
            : (place.approximate
              ? 'Approximate pin placed at ' + place.label + ' in ' + place.barangay + '. Verify it against the map imagery.'
              : 'Pin placed at ' + place.label + ' in ' + place.barangay + '.'));
        } else {
          setStatus(portal === 'resident'
            ? 'The map is unavailable right now. Reload the page or enter the location details below.'
            : 'The report map is unavailable. Reload the page and try again.');
        }
      } else {
        focusPlace(place);
      }
    }

    function renderSearch() {
      var query = input.value.trim();
      requestId++;
      if (controller) controller.abort();
      clearTimeout(timer);
      placeResults = [];
      setStatus('');

      if (!query) {
        localSuggestions = [];
        closeResults();
        if (selectionMode !== 'pin') {
          var overlay = window.MapCN && window.MapCN.barangayOverlay;
          if (overlay && typeof overlay.search === 'function') overlay.search('');
        }
        return;
      }

      localSuggestions = getAreaSuggestions(query)
        .concat(getBarangaySuggestions(query), getLandmarkSuggestions(query));
      renderResults();
      if (query.length < 3) {
        if (!localSuggestions.length) {
          setStatus(portal === 'resident'
            ? 'Type at least 3 characters to search for a location.'
            : 'Type at least 3 characters to search addresses and places.');
        }
        return;
      }

      var currentRequestId = requestId;
      timer = setTimeout(function() {
        renderSearchMessage('Searching...');
        searchPlace(query, currentRequestId);
      }, 300);
    }

    function selectActiveOption() {
      var options = resultsElement.querySelectorAll('.dcvs-place-option');
      if (!options.length) return false;
      var selected = options[Math.max(activeIndex, 0)];
      selected.click();
      return true;
    }

    input.addEventListener('input', function() {
      pendingBarangay = null;
      renderSearch();
    });
    input.addEventListener('keydown', function(event) {
      var options = resultsElement.querySelectorAll('.dcvs-place-option');
      if (event.key === 'ArrowDown' && options.length) {
        event.preventDefault();
        activeIndex = (activeIndex + 1) % options.length;
      } else if (event.key === 'ArrowUp' && options.length) {
        event.preventDefault();
        activeIndex = (activeIndex - 1 + options.length) % options.length;
      } else if (event.key === 'Escape') {
        closeResults();
        return;
      } else if (event.key === 'Enter') {
        event.preventDefault();
        if (selectActiveOption()) return;
        renderSearch();
        if (placeResults.length) selectPlace(placeResults[0]);
        return;
      } else {
        return;
      }
      Array.prototype.forEach.call(options, function(option, index) {
        option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
      });
    });

    document.addEventListener('click', function(event) {
      if (!wrapper.contains(event.target)) closeResults();
    });
    document.addEventListener('dcvs:barangaysready', function() {
      if (input.value.trim()) renderSearch();
      if (pendingBarangay) selectBarangay(pendingBarangay);
    });

    if (mapElement) {
      mapElement.addEventListener('dcvs:landmarksready', function() {
        if (input.value.trim()) renderSearch();
      });
      mapElement.addEventListener('mapcn:load', function(event) {
        map = event.detail && event.detail.map;
        if (pendingPlace) focusPlace(pendingPlace);
      });
      if (window.MapCN && window.MapCN.map) map = window.MapCN.map;
    }
  }

  document.querySelectorAll('.dcvs-place-search').forEach(initializeSearch);
})();
