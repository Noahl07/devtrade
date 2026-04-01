"use strict";
(self["webpackChunk"] = self["webpackChunk"] || []).push([["app"],{

/***/ "./assets/app.js"
/*!***********************!*\
  !*** ./assets/app.js ***!
  \***********************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _styles_app_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./styles/app.css */ "./assets/styles/app.css");
// dev.trade — app.js


// ── Navbar burger mobile ──
var burger = document.getElementById('navBurger');
var mobileMenu = document.getElementById('navMobile');
if (burger && mobileMenu) {
  burger.addEventListener('click', function () {
    var isOpen = mobileMenu.classList.toggle('is-open');
    burger.setAttribute('aria-expanded', isOpen);
  });

  // Ferme le menu si on clique en dehors
  document.addEventListener('click', function (e) {
    if (!burger.contains(e.target) && !mobileMenu.contains(e.target)) {
      mobileMenu.classList.remove('is-open');
      burger.setAttribute('aria-expanded', false);
    }
  });
}

// ── Flash auto-dismiss après 5s ──
document.querySelectorAll('.flash').forEach(function (flash) {
  setTimeout(function () {
    flash.style.opacity = '0';
    flash.style.transition = 'opacity 0.4s ease';
    setTimeout(function () {
      return flash.remove();
    }, 400);
  }, 5000);
});

/***/ },

/***/ "./assets/styles/app.css"
/*!*******************************!*\
  !*** ./assets/styles/app.css ***!
  \*******************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ }

},
/******/ __webpack_require__ => { // webpackRuntimeModules
/******/ var __webpack_exec__ = (moduleId) => (__webpack_require__(__webpack_require__.s = moduleId))
/******/ var __webpack_exports__ = (__webpack_exec__("./assets/app.js"));
/******/ }
]);
//# sourceMappingURL=data:application/json;charset=utf-8;base64,eyJ2ZXJzaW9uIjozLCJmaWxlIjoiYXBwLmpzIiwibWFwcGluZ3MiOiI7Ozs7Ozs7Ozs7O0FBQUE7QUFDMEI7O0FBRTFCO0FBQ0EsSUFBTUEsTUFBTSxHQUFHQyxRQUFRLENBQUNDLGNBQWMsQ0FBQyxXQUFXLENBQUM7QUFDbkQsSUFBTUMsVUFBVSxHQUFHRixRQUFRLENBQUNDLGNBQWMsQ0FBQyxXQUFXLENBQUM7QUFFdkQsSUFBSUYsTUFBTSxJQUFJRyxVQUFVLEVBQUU7RUFDdEJILE1BQU0sQ0FBQ0ksZ0JBQWdCLENBQUMsT0FBTyxFQUFFLFlBQU07SUFDbkMsSUFBTUMsTUFBTSxHQUFHRixVQUFVLENBQUNHLFNBQVMsQ0FBQ0MsTUFBTSxDQUFDLFNBQVMsQ0FBQztJQUNyRFAsTUFBTSxDQUFDUSxZQUFZLENBQUMsZUFBZSxFQUFFSCxNQUFNLENBQUM7RUFDaEQsQ0FBQyxDQUFDOztFQUVGO0VBQ0FKLFFBQVEsQ0FBQ0csZ0JBQWdCLENBQUMsT0FBTyxFQUFFLFVBQUNLLENBQUMsRUFBSztJQUN0QyxJQUFJLENBQUNULE1BQU0sQ0FBQ1UsUUFBUSxDQUFDRCxDQUFDLENBQUNFLE1BQU0sQ0FBQyxJQUFJLENBQUNSLFVBQVUsQ0FBQ08sUUFBUSxDQUFDRCxDQUFDLENBQUNFLE1BQU0sQ0FBQyxFQUFFO01BQzlEUixVQUFVLENBQUNHLFNBQVMsQ0FBQ00sTUFBTSxDQUFDLFNBQVMsQ0FBQztNQUN0Q1osTUFBTSxDQUFDUSxZQUFZLENBQUMsZUFBZSxFQUFFLEtBQUssQ0FBQztJQUMvQztFQUNKLENBQUMsQ0FBQztBQUNOOztBQUVBO0FBQ0FQLFFBQVEsQ0FBQ1ksZ0JBQWdCLENBQUMsUUFBUSxDQUFDLENBQUNDLE9BQU8sQ0FBQyxVQUFBQyxLQUFLLEVBQUk7RUFDakRDLFVBQVUsQ0FBQyxZQUFNO0lBQ2JELEtBQUssQ0FBQ0UsS0FBSyxDQUFDQyxPQUFPLEdBQUcsR0FBRztJQUN6QkgsS0FBSyxDQUFDRSxLQUFLLENBQUNFLFVBQVUsR0FBRyxtQkFBbUI7SUFDNUNILFVBQVUsQ0FBQztNQUFBLE9BQU1ELEtBQUssQ0FBQ0gsTUFBTSxDQUFDLENBQUM7SUFBQSxHQUFFLEdBQUcsQ0FBQztFQUN6QyxDQUFDLEVBQUUsSUFBSSxDQUFDO0FBQ1osQ0FBQyxDQUFDLEM7Ozs7Ozs7Ozs7O0FDN0JGIiwic291cmNlcyI6WyJ3ZWJwYWNrOi8vLy4vYXNzZXRzL2FwcC5qcyIsIndlYnBhY2s6Ly8vLi9hc3NldHMvc3R5bGVzL2FwcC5jc3M/NmJlNiJdLCJzb3VyY2VzQ29udGVudCI6WyIvLyBkZXYudHJhZGUg4oCUIGFwcC5qc1xuaW1wb3J0ICcuL3N0eWxlcy9hcHAuY3NzJztcblxuLy8g4pSA4pSAIE5hdmJhciBidXJnZXIgbW9iaWxlIOKUgOKUgFxuY29uc3QgYnVyZ2VyID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ25hdkJ1cmdlcicpO1xuY29uc3QgbW9iaWxlTWVudSA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCduYXZNb2JpbGUnKTtcblxuaWYgKGJ1cmdlciAmJiBtb2JpbGVNZW51KSB7XG4gICAgYnVyZ2VyLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgKCkgPT4ge1xuICAgICAgICBjb25zdCBpc09wZW4gPSBtb2JpbGVNZW51LmNsYXNzTGlzdC50b2dnbGUoJ2lzLW9wZW4nKTtcbiAgICAgICAgYnVyZ2VyLnNldEF0dHJpYnV0ZSgnYXJpYS1leHBhbmRlZCcsIGlzT3Blbik7XG4gICAgfSk7XG5cbiAgICAvLyBGZXJtZSBsZSBtZW51IHNpIG9uIGNsaXF1ZSBlbiBkZWhvcnNcbiAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIChlKSA9PiB7XG4gICAgICAgIGlmICghYnVyZ2VyLmNvbnRhaW5zKGUudGFyZ2V0KSAmJiAhbW9iaWxlTWVudS5jb250YWlucyhlLnRhcmdldCkpIHtcbiAgICAgICAgICAgIG1vYmlsZU1lbnUuY2xhc3NMaXN0LnJlbW92ZSgnaXMtb3BlbicpO1xuICAgICAgICAgICAgYnVyZ2VyLnNldEF0dHJpYnV0ZSgnYXJpYS1leHBhbmRlZCcsIGZhbHNlKTtcbiAgICAgICAgfVxuICAgIH0pO1xufVxuXG4vLyDilIDilIAgRmxhc2ggYXV0by1kaXNtaXNzIGFwcsOocyA1cyDilIDilIBcbmRvY3VtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoJy5mbGFzaCcpLmZvckVhY2goZmxhc2ggPT4ge1xuICAgIHNldFRpbWVvdXQoKCkgPT4ge1xuICAgICAgICBmbGFzaC5zdHlsZS5vcGFjaXR5ID0gJzAnO1xuICAgICAgICBmbGFzaC5zdHlsZS50cmFuc2l0aW9uID0gJ29wYWNpdHkgMC40cyBlYXNlJztcbiAgICAgICAgc2V0VGltZW91dCgoKSA9PiBmbGFzaC5yZW1vdmUoKSwgNDAwKTtcbiAgICB9LCA1MDAwKTtcbn0pO1xuIiwiLy8gZXh0cmFjdGVkIGJ5IG1pbmktY3NzLWV4dHJhY3QtcGx1Z2luXG5leHBvcnQge307Il0sIm5hbWVzIjpbImJ1cmdlciIsImRvY3VtZW50IiwiZ2V0RWxlbWVudEJ5SWQiLCJtb2JpbGVNZW51IiwiYWRkRXZlbnRMaXN0ZW5lciIsImlzT3BlbiIsImNsYXNzTGlzdCIsInRvZ2dsZSIsInNldEF0dHJpYnV0ZSIsImUiLCJjb250YWlucyIsInRhcmdldCIsInJlbW92ZSIsInF1ZXJ5U2VsZWN0b3JBbGwiLCJmb3JFYWNoIiwiZmxhc2giLCJzZXRUaW1lb3V0Iiwic3R5bGUiLCJvcGFjaXR5IiwidHJhbnNpdGlvbiJdLCJzb3VyY2VSb290IjoiIn0=