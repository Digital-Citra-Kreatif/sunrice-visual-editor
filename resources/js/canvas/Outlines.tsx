import type { Rect } from '../bridge/protocol';

export type Outline = {
    /** What sets it apart in the list: a template's key. */
    id: string;
    /**
     * hover: under the pointer. open: the template that is open. selected:
     * the selected node. around: the element around a selected node that
     * has no box of its own, such as a piece of text.
     */
    kind: 'hover' | 'open' | 'selected' | 'around';
    /** Shown on the first box. */
    label: string;
    rects: Rect[];
};

type Props = {
    outlines: Outline[];
    /** How much smaller than life the page is drawn: the boxes are measured in the page's own pixels. */
    scale?: number;
};

/**
 * Boxes drawn over the page in the frame. The page itself is never drawn
 * on: this layer lies above the frame and lets every click through.
 */
export function Outlines({ outlines, scale = 1 }: Props) {
    return (
        <div className="sve-outlines" aria-hidden="true">
            {outlines.flatMap((outline) =>
                outline.rects.map((rect, index) => (
                    <div
                        key={`${outline.kind}:${outline.id}:${index}`}
                        className={`sve-outline sve-outline--${outline.kind}`}
                        style={{ transform: `translate(${rect.x * scale}px, ${rect.y * scale}px)`, width: rect.width * scale, height: rect.height * scale }}
                    >
                        {index === 0 && <span className={`sve-outline__label${rect.y * scale < 18 ? ' sve-outline__label--inside' : ''}`}>{outline.label}</span>}
                    </div>
                )),
            )}
        </div>
    );
}
