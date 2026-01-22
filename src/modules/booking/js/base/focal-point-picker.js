/**
 * Focal Point Picker
 * Adapted from https://www.azulcoding.com/js-focal-point-picker/
 */

function getCenterFocalPoint() {
    return { x: 0.5, y: 0.5 };
}

class FocalPointPicker {
    constructor(imageElement, onChange, defaultPoint) {
        this.image = imageElement;
        this.container = document.createElement("div");
        this.pointer = document.createElement("div");
        this.crosshairVertical = document.createElement("div");
        this.crosshairHorizontal = document.createElement("div");
        this.focalPoint = defaultPoint || getCenterFocalPoint();
        this.onChange = onChange || (() => {});
        this.isDragging = false;
        this.originalNextSibling = imageElement.nextSibling;
        this.stepSize = 0.01;

        this.boundHandleDragStart = this.handleDragStart.bind(this);
        this.boundHandleDragMove = this.handleDragMove.bind(this);
        this.boundHandleDragEnd = this.handleDragEnd.bind(this);
        this.boundHandleKeyDown = this.handleKeyDown.bind(this);

        this.setupStyles();
        this.setupEventListeners();
        this.updatePointerPosition();
    }

    setupStyles() {
        this.container.style.cssText = `
            position: relative;
            display: inline-block;
            cursor: crosshair;
        `;

        this.pointer.style.cssText = `
            position: absolute;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: rgba(255, 0, 0, 0.6);
            border: 3px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            transform: translate(-50%, -50%);
            cursor: move;
            z-index: 1002;
        `;

        const crosshairStyle = `
            position: absolute;
            background-color: rgba(255, 255, 255, 0.7);
            z-index: 1001;
            pointer-events: none;
        `;

        this.crosshairVertical.style.cssText = crosshairStyle + `
            width: 1px;
            height: 100%;
            left: 0;
            top: 0;
        `;

        this.crosshairHorizontal.style.cssText = crosshairStyle + `
            width: 100%;
            height: 1px;
            top: 0;
            left: 0;
        `;

        this.image.parentNode?.insertBefore(this.container, this.image);
        this.container.appendChild(this.image);
        this.container.appendChild(this.crosshairVertical);
        this.container.appendChild(this.crosshairHorizontal);
        this.container.appendChild(this.pointer);

        this.pointer.tabIndex = 0;
        this.pointer.setAttribute("role", "slider");
        this.pointer.setAttribute("aria-label", "Focal point");
        this.updateAriaValues();
    }

    setupEventListeners() {
        this.container.addEventListener("mousedown", this.boundHandleDragStart);
        document.addEventListener("mousemove", this.boundHandleDragMove);
        document.addEventListener("mouseup", this.boundHandleDragEnd);
        this.pointer.addEventListener("keydown", this.boundHandleKeyDown);
    }

    updatePointerPosition() {
        this.pointer.style.left = `${this.focalPoint.x * 100}%`;
        this.pointer.style.top = `${this.focalPoint.y * 100}%`;

        // Update crosshair positions
        this.crosshairVertical.style.left = `${this.focalPoint.x * 100}%`;
        this.crosshairHorizontal.style.top = `${this.focalPoint.y * 100}%`;
    }

    handleDragStart(event) {
        event.preventDefault();
        event.stopPropagation();

        // Set focal point at click position immediately
        const rect = this.image.getBoundingClientRect();
        this.setFocalPoint({
            x: (event.clientX - rect.left) / rect.width,
            y: (event.clientY - rect.top) / rect.height
        });

        // Start dragging
        this.isDragging = true;
        this.pointer.focus();
    }

    handleDragMove(event) {
        if (!this.isDragging) return;
        event.preventDefault();

        const rect = this.image.getBoundingClientRect();
        this.setFocalPoint({
            x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)),
            y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height))
        });
    }

    handleDragEnd() {
        this.isDragging = false;
    }

    handleKeyDown(event) {
        let newX = this.focalPoint.x;
        let newY = this.focalPoint.y;

        switch (event.key) {
            case "ArrowLeft":
                newX = Math.max(0, newX - this.stepSize);
                break;
            case "ArrowRight":
                newX = Math.min(1, newX + this.stepSize);
                break;
            case "ArrowUp":
                newY = Math.max(0, newY - this.stepSize);
                break;
            case "ArrowDown":
                newY = Math.min(1, newY + this.stepSize);
                break;
            default:
                return;
        }
        event.preventDefault();
        this.setFocalPoint({ x: newX, y: newY });
    }

    updateAriaValues() {
        const positionFormatted = `X: ${Math.round(this.focalPoint.x * 100)}%, Y: ${Math.round(this.focalPoint.y * 100)}%`;
        this.pointer.setAttribute("aria-valuenow", `${Math.round(this.focalPoint.x * 100)}`);
        this.pointer.setAttribute("aria-valuetext", positionFormatted);
        this.pointer.setAttribute("title", positionFormatted);
    }

    getFocalPoint() {
        return this.focalPoint;
    }

    setFocalPoint(newFocalPoint) {
        this.focalPoint = {
            x: Math.max(0, Math.min(1, newFocalPoint.x)),
            y: Math.max(0, Math.min(1, newFocalPoint.y))
        };
        this.updatePointerPosition();
        this.updateAriaValues();
        this.onChange(this.focalPoint);
    }

    dispose() {
        this.container.removeEventListener("mousedown", this.boundHandleDragStart);
        document.removeEventListener("mousemove", this.boundHandleDragMove);
        document.removeEventListener("mouseup", this.boundHandleDragEnd);
        this.pointer.removeEventListener("keydown", this.boundHandleKeyDown);

        this.container.removeChild(this.image);

        if (this.originalNextSibling) {
            this.originalNextSibling.parentNode?.insertBefore(this.image, this.originalNextSibling);
        } else {
            this.container.parentNode?.appendChild(this.image);
        }

        this.container.parentNode?.removeChild(this.container);
    }
}
